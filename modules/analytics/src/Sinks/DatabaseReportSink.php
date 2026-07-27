<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Sinks;

use Cbox\Id\Analytics\Contracts\ReportSink;
use Cbox\Id\Analytics\Models\AnalyticsEvent;
use Cbox\Id\Analytics\ValueObjects\ReportRecord;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Writes {@see ReportRecord}s to the platform's own
 * database — the store for a deployment with no column store, which is the hosted
 * one and every self-host.
 *
 * Idempotent through the ENGINE, not through a read-then-write: `event_id` is the
 * table's primary key and the insert is `insertOrIgnore`, so at-least-once outbox
 * re-delivery collapses under a unique-key conflict with no race window. (MySQL
 * compiles that to INSERT IGNORE, PostgreSQL to ON CONFLICT DO NOTHING.)
 *
 * Fails open, exactly as the ClickHouse sink does: an analytics write must never be
 * what stops a domain event from being delivered.
 *
 * At low volume this is the right store. It is a row store, so the dashboard's
 * GROUP BY aggregates scan an index range rather than a column segment — the
 * degradation with volume is dashboard latency, not wrong numbers, and that latency
 * is the signal to move to ClickHouse rather than a correctness cliff.
 */
class DatabaseReportSink implements ReportSink
{
    public function write(array $records): void
    {
        if ($records === []) {
            return;
        }

        $now = Carbon::now();

        $rows = [];
        foreach ($records as $record) {
            $rows[] = $record->toRow() + [
                'occurred_on' => $record->occurredAt->format('Y-m-d'),
                'ingested_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        try {
            AnalyticsEvent::query()->insertOrIgnore($rows);
        } catch (Throwable) {
            // Fail open: never let an analytics write break event delivery.
        }
    }

    /** A real destination. */
    public function isInert(): bool
    {
        return false;
    }
}
