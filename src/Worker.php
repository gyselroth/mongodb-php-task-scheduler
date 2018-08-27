<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2018 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Worker
{
    /**
     * Scheduler.
     *
     * @var Scheduler
     */
    protected $scheduler;

    /**
     * Database.
     *
     * @var Database
     */
    protected $db;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Container.
     *
     * @var ContainerInterface
     */
    protected $container;

    /**
     * Local queue.
     *
     * @var array
     */
    protected $queue = [];

    /**
     * Current processing job.
     *
     * @var null|array
     */
    protected $current_job;

    /**
     * Process ID (fork posix pid).
     *
     * @var string
     */
    protected $process;

    /**
     * Jobs queue.
     *
     * @var MessageQueue
     */
    protected $jobs;

    /**
     * Init queue.
     *
     * @param ContainerInterface $container
     */
    public function __construct(Scheduler $scheduler, Database $db, LoggerInterface $logger, ?ContainerInterface $container = null)
    {
        $this->process = (string) getmypid();
        $this->scheduler = $scheduler;
        $this->db = $db;
        $this->logger = $logger;
        $this->container = $container;
        $this->jobs = new MessageQueue($db, $scheduler->getJobQueue(), $scheduler->getJobQueueSize(), $logger);
    }

    /**
     * Start worker.
     */
    public function start()
    {
        $this->main();
    }

    /**
     * Cleanup and exit.
     */
    public function cleanup(int $sig)
    {
        $this->handleSignal($sig);
        exit();
    }

    /**
     * Start worker.
     */
    protected function main(): void
    {
        $cursor = $this->jobs->getCursor();
        $this->catchSignal();

        while (true) {
            $this->processLocalQueue();

            if (null === $cursor->current()) {
                if ($cursor->getInnerIterator()->isDead()) {
                    $this->logger->error('job queue cursor is dead, is it a capped collection?', [
                        'category' => get_class($this),
                        'pm' => $this->process,
                    ]);

                    $this->jobs->create();

                    $this->main();

                    break;
                }

                $this->jobs->next($cursor);

                continue;
            }

            $job = $cursor->current();
            $this->jobs->next($cursor);
            $this->queueJob($job);
        }
    }

    /**
     * Catch signals and cleanup.
     *
     * @return Queue
     */
    protected function catchSignal(): self
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'cleanup']);
        pcntl_signal(SIGINT, [$this, 'cleanup']);

        return $this;
    }

    /**
     * Cleanup and exit.
     *
     *
     * @return Process
     */
    protected function handleSignal(int $sig): ?Process
    {
        if (null === $this->current_job) {
            $this->logger->debug('received signal ['.$sig.'], no job is currently processing, exit now', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            return null;
        }

        $this->logger->debug('received signal ['.$sig.'], reschedule current processing job ['.$this->current_job['_id'].']', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        $this->updateJob($this->current_job, JobInterface::STATUS_CANCELED);

        $this->db->{$this->scheduler->getEventQueue()}->insertOne([
            'job' => $this->current_job['_id'],
            'event' => 3,
            'timestamp' => new UTCDateTime(),
        ]);

        return $this->scheduler->addJob($this->current_job['class'], $this->current_job['data'], [
            Scheduler::OPTION_AT => $this->current_job['retry_interval'],
            Scheduler::OPTION_INTERVAL => $this->current_job['interval'],
            Scheduler::OPTION_RETRY => --$this->current_job['retry'],
            Scheduler::OPTION_RETRY_INTERVAL => $this->current_job['retry_interval'],
            Scheduler::OPTION_IGNORE_MAX_CHILDREN => $this->current_job['ignore_max_children'],
        ]);
    }

    /**
     * Queue job.
     */
    protected function queueJob(array $job): bool
    {
        if (!isset($job['status'])) {
            return false;
        }

        if (true === $this->collectJob($job, JobInterface::STATUS_PROCESSING)) {
            $this->processJob($job);
        } elseif (JobInterface::STATUS_POSTPONED === $job['status']) {
            $this->logger->debug('found postponed job ['.$job['_id'].'] to requeue', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            $this->queue[] = $job;
        }

        return true;
    }

    /**
     * Update job status.
     */
    protected function collectJob(array $job, int $status, $from_status = JobInterface::STATUS_WAITING): bool
    {
        $set = [
             'status' => $status,
        ];

        //isset($job['started']) required due compatibility between 1.x and 2.x
        if (JobInterface::STATUS_PROCESSING === $status && isset($job['started'])) {
            $set['started'] = new UTCDateTime();
        }

        $result = $this->db->{$this->scheduler->getJobQueue()}->updateMany([
            '_id' => $job['_id'],
            'status' => $from_status,
            '$isolated' => true,
        ], [
            '$set' => $set,
        ]);

        if (1 === $result->getModifiedCount()) {
            $this->logger->debug('job ['.$job['_id'].'] updated to status ['.$status.']', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            $this->db->{$this->scheduler->getEventQueue()}->insertOne([
                'job' => $job['_id'],
                'event' => $status,
                'timestamp' => new UTCDateTime(),
            ]);

            return true;
        }

        $this->logger->debug('job ['.$job['_id'].'] is already collected with status ['.$status.']', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        return false;
    }

    /**
     * Update job status.
     */
    protected function updateJob(array $job, int $status): bool
    {
        $set = [
            'status' => $status,
        ];

        //isset($job['ended']) required due compatibility between 1.x and 2.x
        if ($status >= JobInterface::STATUS_DONE && isset($job['ended'])) {
            $set['ended'] = new UTCDateTime();
        }

        $result = $this->db->{$this->scheduler->getJobQueue()}->updateMany([
            '_id' => $job['_id'],
            '$isolated' => true,
        ], [
            '$set' => $set,
        ]);

        return $result->isAcknowledged();
    }

    /**
     * Check local queue for postponed jobs.
     */
    protected function processLocalQueue(): bool
    {
        $now = new UTCDateTime();
        foreach ($this->queue as $key => $job) {
            if ($job['at'] <= $now) {
                $this->logger->info('postponed job ['.$job['_id'].'] ['.$job['class'].'] can now be executed', [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]);

                unset($this->queue[$key]);
                $job['at'] = null;

                if (true === $this->collectJob($job, JobInterface::STATUS_PROCESSING, JobInterface::STATUS_POSTPONED)) {
                    $this->processJob($job);
                }
            }
        }

        return true;
    }

    /**
     * Process job.
     */
    protected function processJob(array $job): ObjectId
    {
        if ($job['at'] instanceof UTCDateTime) {
            $this->updateJob($job, JobInterface::STATUS_POSTPONED);
            $this->queue[] = $job;

            $this->logger->debug('execution of job ['.$job['_id'].'] ['.$job['class'].'] is postponed at ['.$job['at']->toDateTime()->format('c').']', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            return $job['_id'];
        }

        $this->logger->debug('execute job ['.$job['_id'].'] ['.$job['class'].']', [
            'category' => get_class($this),
            'pm' => $this->process,
            'params' => $job['data'],
        ]);

        $this->current_job = $job;

        /*$this->{$this->scheduler->getEventQueue()}->insertOne([
            'job' => $job['_id'],
            'event' => JobInterface::STATUS_PROCESSING,
            'timestamp' => new UTCDateTime(),
        ]);*/

        try {
            $this->executeJob($job);
            $this->current_job = null;
        } catch (\Exception $e) {
            $this->logger->error('failed execute job ['.$job['_id'].']', [
                'category' => get_class($this),
                'pm' => $this->process,
                'exception' => $e,
            ]);

            $this->updateJob($job, JobInterface::STATUS_FAILED);
            $this->current_job = null;

            $this->db->{$this->scheduler->getEventQueue()}->insertOne([
                'job' => $job['_id'],
                'event' => JobInterface::STATUS_FAILED,
                'timestamp' => new UTCDateTime(),
                'data' => serialize($e),
            ]);

            if ($job['retry'] >= 0) {
                $this->logger->debug('failed job ['.$job['_id'].'] has a retry interval of ['.$job['retry'].']', [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]);

                $job = $this->scheduler->addJob($job['class'], $job['data'], [
                    Scheduler::OPTION_AT => time() + $job['retry_interval'],
                    Scheduler::OPTION_INTERVAL => $job['interval'],
                    Scheduler::OPTION_RETRY => --$job['retry'],
                    Scheduler::OPTION_RETRY_INTERVAL => $job['retry_interval'],
                    Scheduler::OPTION_IGNORE_MAX_CHILDREN => $job['ignore_max_children'],
                ]);

                return $job->getId();
            }
        }

        if ($job['interval'] >= 0) {
            $this->logger->debug('job ['.$job['_id'].'] has an interval of ['.$job['interval'].'s]', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            $job = $this->scheduler->addJob($job['class'], $job['data'], [
                Scheduler::OPTION_AT => time() + $job['interval'],
                Scheduler::OPTION_INTERVAL => $job['interval'],
                Scheduler::OPTION_RETRY => $job['retry'],
                Scheduler::OPTION_RETRY_INTERVAL => $job['retry_interval'],
                Scheduler::OPTION_IGNORE_MAX_CHILDREN => $job['ignore_max_children'],
            ]);

            return $job->getId();
        }

        return $job['_id'];
    }

    /**
     * Execute job.
     */
    protected function executeJob(array $job): bool
    {
        if (!class_exists($job['class'])) {
            throw new Exception\InvalidJob('job class does not exists');
        }

        if (null === $this->container) {
            $instance = new $job['class']();
        } else {
            $instance = $this->container->get($job['class']);
        }

        if (!($instance instanceof JobInterface)) {
            throw new Exception\InvalidJob('job must implement JobInterface');
        }

        $result = $instance
            ->setData($job['data'])
            ->setId($job['_id'])
            ->start();

        $data = serialize($result);
        $return = $this->updateJob($job, JobInterface::STATUS_DONE);

        $this->db->{$this->scheduler->getEventQueue()}->insertOne([
            'job' => $job['_id'],
            'event' => JobInterface::STATUS_DONE,
            'timestamp' => new UTCDateTime(),
            'data' => $result,
        ]);

        return $return;
    }
}
