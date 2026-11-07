<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

readonly class ConnectionState
{
    public function __construct(public string $id, public string $cursor, public int $importedCount, public int $backfillLimit, public DateTimeImmutable $importStartedAt, public ?DateTimeImmutable $lastSuccessAt, public ?DateTimeImmutable $lastAttemptAt, public ?DateTimeImmutable $latestEventAt, public ?string $errorCode, public ?string $pendingOperation) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['last_success_at', 'last_attempt_at', 'latest_event_at', 'error_code', 'pending_operation'] as $field) {
            if (! array_key_exists($field, $data)) {
                Wire::fail();
            }
        }
        if (! is_string($data['cursor'] ?? null)) {
            Wire::fail();
        }

        return new self(Wire::text($data['id'] ?? null), $data['cursor'], Wire::integer($data['imported_count'] ?? null), Wire::integer($data['backfill_limit'] ?? null), Wire::date($data['import_started_at'] ?? null),
            $data['last_success_at'] === null ? null : Wire::date($data['last_success_at']),
            $data['last_attempt_at'] === null ? null : Wire::date($data['last_attempt_at']),
            $data['latest_event_at'] === null ? null : Wire::date($data['latest_event_at']),
            $data['error_code'] === null ? null : Wire::text($data['error_code']),
            $data['pending_operation'] === null ? null : Wire::text($data['pending_operation']));
    }
}
