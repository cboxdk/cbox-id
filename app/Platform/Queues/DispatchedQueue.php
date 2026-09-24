<?php

declare(strict_types=1);

namespace App\Platform\Queues;

/**
 * One queue this application puts jobs on: a connection and a queue name on it.
 */
readonly class DispatchedQueue
{
    public function __construct(
        public string $connection,
        public string $queue,
    ) {}

    public function key(): string
    {
        return $this->connection.':'.$this->queue;
    }
}
