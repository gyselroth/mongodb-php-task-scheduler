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
use MongoDB\Database;
use Psr\Log\LoggerInterface;
use TaskScheduler\Exception\InvalidArgumentException;
use TaskScheduler\Exception\SpawnForkException;

class WorkerManager
{
    use InjectTrait;

    /**
     * Queue options.
     */
    public const OPTION_PM = 'pm';
    public const OPTION_MAX_CHILDREN = 'max_children';
    public const OPTION_MIN_CHILDREN = 'min_children';

    /**
     * Process handling.
     */
    public const PM_DYNAMIC = 'dynamic';
    public const PM_STATIC = 'static';
    public const PM_ONDEMAND = 'ondemand';

    /**
     * Fork handler actions.
     */
    public const TYPE_JOB = 1;
    public const TYPE_EVENT = 2;
    public const TYPE_WORKER_SPAWN = 3;
    public const TYPE_WORKER_KILL = 4;

    /**
     * Process management.
     *
     * @var string
     */
    protected $pm = self::PM_DYNAMIC;

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
     * Max children.
     *
     * @var int
     */
    protected $max_children = 2;

    /**
     * Min children.
     *
     * @var int
     */
    protected $min_children = 1;

    /**
     * Forks.
     *
     * @var array
     */
    protected $forks = [];

    /**
     * Worker/Job mapping.
     *
     * @var array
     */
    protected $job_map = [];

    /**
     * Queue (Communication between TaskScheduler\Queue and TaskScheduler\WorkerManager).
     *
     * @var resource
     */
    protected $queue;

    /**
     * Hold queue.
     *
     * @var array
     */
    protected $onhold = [];

    /**
     * Worker factory.
     *
     * @var WorkerFactoryInterface
     */
    protected $factory;

    /**
     * Init queue.
     */
    public function __construct(WorkerFactoryInterface $factory, LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->setOptions($config);
        $this->factory = $factory;
    }

    /**
     * Set options.
     */
    public function setOptions(array $config = []): self
    {
        foreach ($config as $option => $value) {
            switch ($option) {
                case self::OPTION_MAX_CHILDREN:
                case self::OPTION_MIN_CHILDREN:
                    if (!is_int($value)) {
                        throw new InvalidArgumentException($option.' needs to be an integer');
                    }

                    $this->{$option} = $value;

                break;
                case self::OPTION_PM:
                    if (!defined('self::PM_'.strtoupper($value))) {
                        throw new InvalidArgumentException($value.' is not a valid process handling type (static, dynamic, ondemand)');
                    }

                    $this->{$option} = $value;

                break;
                default:
                    throw new InvalidArgumentException('invalid option '.$option.' given');
            }
        }

        if ($this->min_children > $this->max_children) {
            throw new InvalidArgumentException('option min_children must not be greater than option max_children');
        }

        return $this;
    }

    /**
     * Startup (blocking process).
     */
    public function process(): void
    {
        $this->queue = msg_get_queue(ftok(__DIR__.DIRECTORY_SEPARATOR.'Queue.php', 't'));
        $this->catchSignal();
        $this->spawnInitialWorkers();
        $this->main();
    }

    /**
     * Wait for child and terminate.
     */
    public function exitWorker(int $sig, array $pid): self
    {
        $this->logger->debug('worker ['.$pid['pid'].'] exit with ['.$sig.']', [
            'category' => get_class($this),
        ]);

        pcntl_waitpid($pid['pid'], $status, WNOHANG | WUNTRACED);

        foreach ($this->forks as $id => $process) {
            if ($process === $pid['pid']) {
                unset($this->forks[$id]);

                if (isset($this->job_map[$id])) {
                    unset($this->job_map[$id]);
                }

                @msg_send($this->queue, WorkerManager::TYPE_WORKER_KILL, [
                    '_id' => $id,
                    'pid' => $pid['pid'],
                    'sig' => $sig,
                ]);
            }
        }

        $this->spawnMinimumWorkers();

        return $this;
    }

    /**
     * Count children.
     */
    public function count(): int
    {
        return count($this->forks);
    }

    /**
     * Cleanup.
     */
    public function cleanup(int $sig): void
    {
        $this->logger->debug('received signal ['.$sig.']', [
            'category' => get_class($this),
        ]);

        foreach ($this->getForks() as $id => $pid) {
            $this->logger->debug('forward signal ['.$sig.'] to worker ['.$id.'] running with pid ['.$pid.']', [
                'category' => get_class($this),
            ]);

            posix_kill($pid, $sig);
        }

        $this->exit();
    }

    /**
     * Start initial workers.
     */
    protected function spawnInitialWorkers()
    {
        $this->logger->debug('spawn initial ['.$this->min_children.'] workers', [
            'category' => get_class($this),
        ]);

        if (self::PM_DYNAMIC === $this->pm || self::PM_STATIC === $this->pm) {
            for ($i = $this->count(); $i < $this->min_children; ++$i) {
                $this->spawnWorker();
            }
        }
    }

    /**
     * Start minumum number of workers.
     */
    protected function spawnMinimumWorkers()
    {
        $this->logger->debug('verify that the minimum number ['.$this->min_children.'] of workers are running', [
            'category' => get_class($this),
        ]);

        for ($i = $this->count(); $i < $this->min_children; ++$i) {
            $this->spawnWorker();
        }
    }

    /**
     * Start worker.
     *
     * @see https://github.com/mongodb/mongo-php-driver/issues/828
     * @see https://github.com/mongodb/mongo-php-driver/issues/174
     */
    protected function spawnWorker(?ObjectId $job = null)
    {
        $this->logger->debug('spawn new worker', [
            'category' => get_class($this),
        ]);

        $id = new ObjectId();
        $pid = pcntl_fork();

        if (-1 === $pid) {
            throw new SpawnForkException('failed to spawn new worker');
        }

        if (!$pid) {
            $worker = $this->factory->buildWorker($id);

            if (null === $job) {
                $worker->processAll();
            } else {
                $worker->processOne($job);
            }

            exit();
        }

        msg_send($this->queue, WorkerManager::TYPE_WORKER_SPAWN, [
            '_id' => $id,
            'pid' => $pid,
        ]);

        $this->forks[(string) $id] = $pid;
        $this->logger->debug('spawned worker ['.$id.'] with pid ['.$pid.']', [
            'category' => get_class($this),
        ]);

        return $pid;
    }

    /**
     * Get forks (array of pid's).
     */
    protected function getForks(): array
    {
        return $this->forks;
    }

    /**
     * Main.
     */
    protected function main(): void
    {
        while ($this->loop()) {
            if (count($this->onhold) > 0 || !$this->loop()) {
                $wait = MSG_IPC_NOWAIT;
                usleep(200);
                $this->processLocalQueue();
            } else {
                $wait = 0;
            }

            if (msg_receive($this->queue, 0, $type, 16384, $msg, true, $wait)) {
                $this->logger->debug('received systemv message type ['.$type.']', [
                    'category' => get_class($this),
                ]);

                switch ($type) {
                    case self::TYPE_JOB:
                        $this->handleJob($msg);

                    break;
                    case self::TYPE_EVENT:
                        $this->handleEvent($msg);

                    break;
                    case self::TYPE_WORKER_SPAWN:
                    case self::TYPE_WORKER_KILL:
                        //events handled by queue node
                    break;
                    default:
                        $this->logger->warning('received unknown systemv message type ['.$type.']', [
                            'category' => get_class($this),
                        ]);
                }
            }
        }
    }

    /**
     * Handle events.
     */
    protected function handleEvent(array $event): self
    {
        $this->logger->debug('handle event ['.$event['status'].'] for job ['.$event['job'].']', [
            'category' => get_class($this),
        ]);

        switch ($event['status']) {
            case JobInterface::STATUS_PROCESSING:
                $this->job_map[(string) $event['worker']] = $event['job'];

                return $this;
            case JobInterface::STATUS_DONE:
            case JobInterface::STATUS_FAILED:
            case JobInterface::STATUS_TIMEOUT:
                $worker = array_search((string) $event['job'], $this->job_map, false);
                if (false === $worker) {
                    return $this;
                }

                unset($this->job_map[$worker]);

                return $this;

            break;
            case JobInterface::STATUS_CANCELED:
                $worker = array_search($event['job'], $this->job_map, false);
                if (false === $worker) {
                    return $this;
                }

                $this->logger->debug('received cancel event for job ['.$event['job'].'] running on worker ['.$worker.']', [
                    'category' => get_class($this),
                ]);

                if (isset($this->forks[(string) $worker])) {
                    $this->logger->debug('found running worker ['.$worker.'] on this queue node, terminate it now', [
                        'category' => get_class($this),
                    ]);

                    unset($this->job_map[(string) $worker]);
                    posix_kill($this->forks[(string) $worker], SIGKILL);
                }

                return $this;
            default:
                $this->logger->warning('received event ['.$event['_id'].'] with unknown status ['.$event['status'].']', [
                    'category' => get_class($this),
                ]);

                return $this;
        }
    }

    /**
     * Process onhold (only used if pm === ondemand or for postponed FORCE_SPAWN jobs).
     */
    protected function processLocalQueue(): self
    {
        foreach ($this->onhold as $id => $job) {
            if ($job['options']['at'] <= time() && ($this->count() < $this->max_children || true === $job['options']['force_spawn'])) {
                $this->logger->debug('hold ondemand job ['.$id.'] may no be executed', [
                    'category' => get_class($this),
                ]);

                unset($this->onhold[$id]);
                $this->spawnWorker($job['_id']);
            }
        }

        return $this;
    }

    /**
     * Handle job.
     */
    protected function handleJob(array $job): self
    {
        if (true === $job['options'][Scheduler::OPTION_FORCE_SPAWN]) {
            if ($job['options']['at'] > time()) {
                $this->logger->debug('found postponed job ['.$job['_id'].'] with force_spawn, keep in local queue', [
                    'category' => get_class($this),
                ]);

                $this->onhold[(string) $job['_id']] = $job;

                return $this;
            }

            $this->logger->debug('job ['.$job['_id'].'] deployed with force_spawn, spawn new worker', [
                'category' => get_class($this),
            ]);

            $this->spawnWorker($job['_id']);

            return $this;
        }

        if (self::PM_ONDEMAND === $this->pm) {
            if ($job['options']['at'] > time()) {
                $this->logger->debug('found ondemand postponed job ['.$job['_id'].'], keep in local queue', [
                    'category' => get_class($this),
                ]);

                $this->onhold[(string) $job['_id']] = $job;

                return $this;
            }

            if ($this->count() < $this->max_children) {
                $this->spawnWorker($job['_id']);
            } else {
                $this->onhold[(string) $job['_id']] = $job;
            }

            return $this;
        }

        if ($this->count() < $this->max_children && self::PM_DYNAMIC === $this->pm) {
            $this->logger->debug('max_children ['.$this->max_children.'] workers not reached ['.$this->count().'], spawn new worker', [
                'category' => get_class($this),
            ]);

            $this->spawnWorker();

            return $this;
        }

        $this->logger->debug('max children ['.$this->max_children.'] reached for job ['.$job['_id'].'], do not spawn new worker', [
            'category' => get_class($this),
        ]);

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
        pcntl_signal(SIGCHLD, [$this, 'exitWorker']);

        return $this;
    }
}
