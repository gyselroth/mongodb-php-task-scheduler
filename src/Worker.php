<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      gyselroth™  (http://www.gyselroth.com)
 * @copyright   Copryright (c) 2017-2021 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TaskScheduler\Exception\InvalidJobException;

class Worker
{
    use InjectTrait;

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
     * @var int
     */
    protected $process;

    /**
     * Jobs queue.
     *
     * @var MessageQueue
     */
    protected $jobs;

    /**
     * Worker ID.
     *
     * @var ObjectId
     */
    protected $id;

    /**
     * Init worker.
     */
    public function __construct(ObjectId $id, Scheduler $scheduler, Database $db, LoggerInterface $logger, ?ContainerInterface $container = null)
    {
        $this->id = $id;
        $this->process = getmypid();
        $this->scheduler = $scheduler;
        $this->db = $db;
        $this->logger = $logger;
        $this->container = $container;
        $this->jobs = new MessageQueue($db, $scheduler->getJobQueue(), $scheduler->getJobQueueSize(), $logger);
    }

    /**
     * Handle worker timeout.
     */
    public function timeout(): ?ObjectId
    {
        if (null === $this->current_job) {
            $this->logger->debug('reached worker timeout signal, no job is currently processing, ignore it', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            return null;
        }

        $this->logger->debug('received timeout signal, reschedule current processing job ['.$this->current_job['_id'].']', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        $this->updateJob($this->current_job, JobInterface::STATUS_TIMEOUT);

        $this->db->{$this->scheduler->getEventQueue()}->insertOne([
            'job' => $this->current_job['_id'],
            'worker' => $this->id,
            'status' => JobInterface::STATUS_TIMEOUT,
            'timestamp' => new UTCDateTime(),
        ]);

        $job = $this->current_job;

        if (0 !== $job['options']['retry']) {
            $this->logger->debug('failed job ['.$job['_id'].'] has a retry interval of ['.$job['options']['retry'].']', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            --$job['options']['retry'];
            $job['options']['at'] = time() + $job['options']['retry_interval'];
            $job = $this->scheduler->addJob($job['class'], $job['data'], $job['options']);

            return $job->getId();
        }
        if ($job['options']['interval'] > 0) {
            $this->logger->debug('job ['.$job['_id'].'] has an interval of ['.$job['options']['interval'].'s]', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            $job['options']['at'] = time() + $job['options']['interval'];
            $job = $this->scheduler->addJob($job['class'], $job['data'], $job['options']);

            return $job->getId();
        }
        if ($job['options']['interval'] <= -1) {
            $this->logger->debug('job ['.$job['_id'].'] has an endless interval', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            unset($job['options']['at']);
            $job = $this->scheduler->addJob($job['class'], $job['data'], $job['options']);

            return $job->getId();
        }

        $this->current_job = null;
        posix_kill($this->process, SIGTERM);

        return null;
    }

    /**
     * Start worker.
     */
    public function processAll(): void
    {
        $this->logger->info('start job listener', [
            'category' => get_class($this),
        ]);

        $cursor = $this->jobs->getCursor([
            'options.force_spawn' => false,
            '$or' => [
                ['status' => JobInterface::STATUS_WAITING],
                ['status' => JobInterface::STATUS_POSTPONED],
            ],
        ]);

        $this->catchSignal();

        while ($this->loop()) {
            $this->processLocalQueue();

            if (null === $cursor->current()) {
                if ($cursor->getInnerIterator()->isDead()) {
                    $this->logger->error('job queue cursor is dead, is it a capped collection?', [
                        'category' => get_class($this),
                        'pm' => $this->process,
                    ]);

                    $this->jobs->create();

                    $this->processAll();

                    break;
                }

                $this->jobs->next($cursor, function () {
                    $this->processAll();
                });

                continue;
            }

            $job = $cursor->current();

            $this->logger->debug('found job ['.$job['_id'].'] in queue with status ['.$job['status'].']', [
                'category' => get_class($this),
            ]);

            $this->jobs->next($cursor, function () {
                $this->processAll();
            });

            $this->queueJob($job);
        }
    }

    /**
     * Process one.
     */
    public function processOne(ObjectId $id): void
    {
        $this->catchSignal();

        $this->logger->debug('process job ['.$id.'] and exit', [
            'category' => get_class($this),
        ]);

        try {
            $job = $this->scheduler->getJob($id)->toArray();
            $this->queueJob($job);
        } catch (\Exception $e) {
            $this->logger->error('failed process job ['.$id.']', [
                'category' => get_class($this),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Cleanup and exit.
     */
    public function cleanup()
    {
        $this->saveState();

        if (null === $this->current_job) {
            $this->logger->debug('received cleanup call on worker ['.$this->id.'], no job is currently processing, exit now', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            $this->exit();

            return null;
        }

        $this->logger->debug('received cleanup call on worker ['.$this->id.'], reschedule current processing job ['.$this->current_job['_id'].']', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        $this->updateJob($this->current_job, JobInterface::STATUS_CANCELED);

        $this->db->{$this->scheduler->getEventQueue()}->insertOne([
            'job' => $this->current_job['_id'],
            'worker' => $this->id,
            'status' => JobInterface::STATUS_CANCELED,
            'timestamp' => new UTCDateTime(),
        ]);

        $options = $this->current_job['options'];
        $options['at'] = 0;

        $result = $this->scheduler->addJob($this->current_job['class'], $this->current_job['data'], $options)->getId();
        $this->exit();

        return $result;
    }

    /**
     * Save local queue.
     */
    protected function saveState(): self
    {
        foreach ($this->queue as $key => $job) {
            $this->db->selectCollection($this->scheduler->getJobQueue())->updateOne(
                ['_id' => $job['_id'], '$isolated' => true],
                ['$setOnInsert' => $job],
                ['upsert' => true]
            );
        }

        return $this;
    }

    /**
     * Catch signals and cleanup.
     */
    protected function catchSignal(): self
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'cleanup']);
        pcntl_signal(SIGINT, [$this, 'cleanup']);
        pcntl_signal(SIGALRM, [$this, 'timeout']);

        return $this;
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

            $this->queue[(string) $job['_id']] = $job;
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

        if (JobInterface::STATUS_PROCESSING === $status) {
            $set['started'] = new UTCDateTime();
            $set['worker'] = $this->id;
        }

        $result = $this->db->{$this->scheduler->getJobQueue()}->updateMany([
            '_id' => $job['_id'],
            'status' => $from_status,
            '$isolated' => true,
        ], [
            '$set' => $set,
        ]);

        $this->logger->debug('collect job ['.$job['_id'].'] with status ['.$from_status.']', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        if (1 === $result->getModifiedCount()) {
            $this->logger->debug('job ['.$job['_id'].'] collected; update status to ['.$status.']', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            $this->db->{$this->scheduler->getEventQueue()}->insertOne([
                'job' => $job['_id'],
                'worker' => $this->id,
                'status' => $status,
                'timestamp' => new UTCDateTime(),
            ]);

            return true;
        }

        $this->logger->debug('job ['.$job['_id'].'] is already collected with status ['.$job['status'].']', [
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

        if ($status >= JobInterface::STATUS_DONE) {
            $set['ended'] = new UTCDateTime();

            if (isset($job['progress'])) {
                $set['progress'] = 100.0;
            }
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
        $now = time();
        foreach ($this->queue as $key => $job) {
            $this->db->{$this->scheduler->getJobQueue()}->updateOne(
                ['_id' => $job['_id'], '$isolated' => true],
                ['$setOnInsert' => $job],
                ['upsert' => true]
            );

            if ($job['options']['at'] <= $now) {
                $this->logger->info('postponed job ['.$job['_id'].'] ['.$job['class'].'] can now be executed', [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]);

                unset($this->queue[$key]);
                $job['options']['at'] = 0;

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
        $now = $job_start_time = time();

        if ($job['options']['at'] > $now) {
            $this->updateJob($job, JobInterface::STATUS_POSTPONED);
            $job['status'] = JobInterface::STATUS_POSTPONED;
            $this->queue[(string) $job['_id']] = $job;

            $this->logger->debug('execution of job ['.$job['_id'].'] ['.$job['class'].'] is postponed at ['.$job['options']['at'].']', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            return $job['_id'];
        }

        $this->logger->debug('execute job ['.$job['_id'].'] ['.$job['class'].'] on worker ['.$this->id.']', [
            'category' => get_class($this),
            'pm' => $this->process,
            'options' => $job['options'],
            'params' => $job['data'],
        ]);

        $this->current_job = $job;
        pcntl_alarm($job['options']['timeout']);

        try {
            $this->executeJob($job);
            $this->current_job = null;
        } catch (\Throwable $e) {
            pcntl_alarm(0);

            $this->logger->error('failed execute job ['.$job['_id'].'] of type ['.$job['class'].'] on worker ['.$this->id.']', [
                'category' => get_class($this),
                'pm' => $this->process,
                'exception' => $e,
            ]);

            $this->updateJob($job, JobInterface::STATUS_FAILED);
            $this->current_job = null;

            $this->db->{$this->scheduler->getEventQueue()}->insertOne([
                'job' => $job['_id'],
                'worker' => $this->id,
                'status' => JobInterface::STATUS_FAILED,
                'timestamp' => new UTCDateTime(),
                'exception' => [
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'code' => $e->getCode(),
                ],
            ]);

            if (0 !== $job['options']['retry']) {
                $this->logger->debug('failed job ['.$job['_id'].'] has a retry interval of ['.$job['options']['retry'].']', [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]);

                --$job['options']['retry'];
                $job['options']['at'] = time() + $job['options']['retry_interval'];
                $job = $this->scheduler->addJob($job['class'], $job['data'], $job['options']);

                return $job->getId();
            }
        }

        pcntl_alarm(0);

        if ($job['options']['interval'] > 0) {
            $this->logger->debug('job ['.$job['_id'].'] has an interval of ['.$job['options']['interval'].'s]', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            $interval_reference = 'end' === $job['options']['interval_reference']
                ? time()
                : $job_start_time;

            $job['options']['at'] = $interval_reference + $job['options']['interval'];
            $job = $this->scheduler->addJob($job['class'], $job['data'], $job['options']);

            return $job->getId();
        }
        if ($job['options']['interval'] <= -1) {
            $this->logger->debug('job ['.$job['_id'].'] has an endless interval', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            unset($job['options']['at']);
            $job = $this->scheduler->addJob($job['class'], $job['data'], $job['options']);

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
            throw new InvalidJobException('job class does not exists');
        }

        if (null === $this->container) {
            $instance = new $job['class']();
        } else {
            $instance = $this->container->get($job['class']);
        }

        if (!($instance instanceof JobInterface)) {
            throw new InvalidJobException('job must implement JobInterface');
        }

        $instance
            ->setData($job['data'])
            ->setId($job['_id'])
            ->setScheduler($this->scheduler)
            ->start();

        $return = $this->updateJob($job, JobInterface::STATUS_DONE);

        $this->db->{$this->scheduler->getEventQueue()}->insertOne([
            'job' => $job['_id'],
            'worker' => $this->id,
            'status' => JobInterface::STATUS_DONE,
            'timestamp' => new UTCDateTime(),
        ]);

        unset($instance);

        return $return;
    }
}
