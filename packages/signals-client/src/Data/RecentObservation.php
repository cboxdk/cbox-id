<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

readonly class RecentObservation
{
    public function __construct(public string $source, public string $eventId, public string $type, public DateTimeImmutable $occurredAt) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Wire::text($data['source'] ?? null), Wire::text($data['event_id'] ?? null), Wire::text($data['observation_type'] ?? null), Wire::date($data['occurred_at'] ?? null));
    }
}
