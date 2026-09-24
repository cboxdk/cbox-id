<?php

declare(strict_types=1);

namespace App\Platform\Queues;

use Carbon\CarbonImmutable;

readonly class ManagerStatus
{
    public function __construct(
        public ManagerState $state,
        public ?ManagerBeat $lastBeat = null,
        /** How long a manager may stay silent before it counts as missing. */
        public int $staleAfterSeconds = 0,
    ) {}

    public function healthy(): bool
    {
        return $this->state !== ManagerState::Missing;
    }

    public function secondsSinceBeat(CarbonImmutable $now): ?int
    {
        return $this->lastBeat === null ? null : max(0, (int) $this->lastBeat->at->diffInSeconds($now, true));
    }
}
