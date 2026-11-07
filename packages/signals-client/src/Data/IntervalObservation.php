<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\IntervalWire;
use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

readonly class IntervalObservation
{
    public function __construct(public string $bucket, public string $hash, public DateTimeImmutable $knownAt, public CountInterval $record) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(IntervalWire::hash($data['bucket'] ?? null), IntervalWire::hash($data['hash'] ?? null), Wire::date($data['known_at'] ?? null), CountInterval::fromArray(Wire::object($data['record'] ?? null)));
    }
}
