<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

readonly class SignalDelivery
{
    public function __construct(public string $id, public string $destinationId, public int $revision, public string $status, public int $attempts, public ?int $lastStatus, public DateTimeImmutable $nextAttempt) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('last_status', $data)) {
            Wire::fail();
        }

        return new self(Wire::text($data['id'] ?? null), Wire::text($data['destination_id'] ?? null), Wire::integer($data['revision'] ?? null, 1), Wire::text($data['status'] ?? null), Wire::integer($data['attempts'] ?? null), $data['last_status'] === null ? null : Wire::integer($data['last_status']), Wire::date($data['next_attempt'] ?? null));
    }
}
