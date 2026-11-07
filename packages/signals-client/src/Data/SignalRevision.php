<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

readonly class SignalRevision
{
    public function __construct(public int $revision, public DateTimeImmutable $occurredAt, public string $status) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Wire::integer($data['revision'] ?? null, 1), Wire::date($data['occurred_at'] ?? null), Wire::text($data['status'] ?? null));
    }
}
