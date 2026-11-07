<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\IntervalWire;
use Cbox\Signals\Support\Wire;

readonly class IntervalAcknowledgement
{
    public function __construct(public string $bucket, public int $revision, public string $hash, public string $outcome) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $revision = Wire::integer($data['revision'] ?? null, 1);
        $outcome = Wire::text($data['outcome'] ?? null);
        if ($revision > 64 || ! in_array($outcome, ['created', 'corrected', 'duplicate'], true)) {
            Wire::fail();
        }

        return new self(IntervalWire::hash($data['bucket'] ?? null), $revision, IntervalWire::hash($data['hash'] ?? null), $outcome);
    }
}
