<?php

declare(strict_types=1);

namespace App\Platform\Queues;

/**
 * One queue at the moment it was looked at: how much is waiting, how long the oldest job
 * has waited, and the pickup SLA it is held to.
 *
 * Counts and ages only — never a job's contents. This is what the health status endpoint prints,
 * and a health response is read by whatever monitors it.
 */
readonly class QueueBacklog
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $slaSeconds,
        public int $pending = 0,
        /** Null when nothing is waiting, or the driver cannot say. */
        public ?int $oldestWaitSeconds = null,
        /** Why the queue could not be read at all, when it could not. */
        public ?string $unreadable = null,
    ) {}

    /** The oldest job has waited longer than the queue's pickup SLA. */
    public function breached(): bool
    {
        return $this->oldestWaitSeconds !== null && $this->oldestWaitSeconds > $this->slaSeconds;
    }

    public function healthy(): bool
    {
        return $this->unreadable === null && ! $this->breached();
    }
}
