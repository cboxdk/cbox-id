<?php

declare(strict_types=1);

namespace App\Platform\Queues;

/**
 * A queue the autoscaler keeps workers on, and the pickup SLA it holds that queue to.
 */
readonly class SupervisedQueue
{
    public function __construct(
        public DispatchedQueue $queue,
        public int $slaSeconds,
    ) {}
}
