<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

readonly class RetentionState
{
    public function __construct(public int $days, public string $status, public ?DateTimeImmutable $operationalPurgedBefore, public ?DateTimeImmutable $historyPurgedBefore, public ?DateTimeImmutable $lastCompletedAt, public DateTimeImmutable $nextRunAt) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $dates = [];
        foreach (['operational_purged_before', 'history_purged_before', 'last_completed_at'] as $key) {
            if (! array_key_exists($key, $data)) {
                Wire::fail();
            }
            $dates[$key] = $data[$key] === null ? null : Wire::date($data[$key]);
        }
        $days = Wire::integer($data['retention_days'] ?? null, 1);
        $status = Wire::text($data['status'] ?? null);
        if ($days > 395 || ! in_array($status, ['ready', 'pending', 'pending_history'], true)) {
            Wire::fail();
        }

        return new self($days, $status, $dates['operational_purged_before'], $dates['history_purged_before'], $dates['last_completed_at'], Wire::date($data['next_run_at'] ?? null));
    }
}
