<?php

declare(strict_types=1);

namespace App\Platform\Queues;

use Carbon\CarbonImmutable;

/**
 * One sign of life from a queue manager: which one, on which host, and when.
 */
readonly class ManagerBeat
{
    public function __construct(
        public string $managerId,
        public string $host,
        public CarbonImmutable $at,
    ) {}

    /**
     * The cache's serialized form — an array at the storage edge, nowhere else.
     *
     * @return array{manager_id: string, host: string, at: int}
     */
    public function toArray(): array
    {
        return ['manager_id' => $this->managerId, 'host' => $this->host, 'at' => $this->at->getTimestamp()];
    }

    /**
     * Rebuild a beat from whatever the cache handed back, or null when it is not one —
     * a store that was flushed half-way, or a key some other code wrote, reads as "no
     * beat", never as a manager that is alive.
     */
    public static function fromStored(mixed $stored): ?self
    {
        if (! is_array($stored)
            || ! is_string($stored['manager_id'] ?? null)
            || ! is_string($stored['host'] ?? null)
            || ! is_int($stored['at'] ?? null)) {
            return null;
        }

        return new self($stored['manager_id'], $stored['host'], CarbonImmutable::createFromTimestamp($stored['at']));
    }
}
