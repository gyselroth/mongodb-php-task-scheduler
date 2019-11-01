<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2019 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

use MongoDB\BSON\ObjectId;

class Process
{
    use EventsTrait;

    /**
     * Job.
     *
     * @var array
     */
    protected $job;

    /**
     * Scheduler.
     *
     * @var Scheduler
     */
    protected $scheduler;

    /**
     * Events queue.
     *
     * @var MessageQueue
     */
    protected $events;

    /**
     * Initialize process.
     */
    public function __construct(array $job, Scheduler $scheduler, MessageQueue $events)
    {
        $this->job = $job;
        $this->scheduler = $scheduler;
        $this->events = $events;
    }

    /**
     * To array.
     */
    public function toArray(): array
    {
        return $this->job;
    }

    /**
     * Get job options.
     */
    public function getOptions(): array
    {
        return $this->job['options'];
    }

    /**
     * Get class.
     */
    public function getClass(): string
    {
        return $this->job['class'];
    }

    /**
     * Get job data.
     */
    public function getData()
    {
        return $this->job['data'];
    }

    /**
     * Get ID.
     */
    public function getId(): ObjectId
    {
        return $this->job['_id'];
    }

    /**
     * Restart job.
     */
    public function getWorker(): ObjectId
    {
        return $this->job['worker'];
    }

    /**
     * Wait for job beeing executed.
     */
    public function wait(): Process
    {
        $cursor = $this->events->getCursor([
            'job' => $this->getId(),
        ]);

        while (true) {
            if (null === $cursor->current()) {
                if ($cursor->getInnerIterator()->isDead()) {
                    $this->events->create();

                    return $this->wait();
                }

                $this->events->next($cursor, function () {
                    $this->wait();
                });

                continue;
            }

            $event = $cursor->current();
            $this->events->next($cursor, function () {
                $this->wait();
            });

            $this->emit($this);
            $this->job['status'] = $event['status'];

            if($event['status'] < JobInterface::STATUS_DONE) {
                continue;
            } elseif (JobInterface::STATUS_FAILED === $this->job['status'] && isset($event['exception'])) {
                throw new $event['exception']['class'](
                    $event['exception']['message'],
                    $event['exception']['code']
                );
            }

            return $this;
        }
    }

    /**
     * Get status.
     */
    public function getStatus(): int
    {
        return $this->job['status'];
    }
}
