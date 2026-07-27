<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Sinks;

use Cbox\Id\Analytics\ClickHouse\ClickHouseConnection;
use Cbox\Id\Analytics\Contracts\ReportSink;
use Cbox\Id\Analytics\ValueObjects\ReportRecord;
use Throwable;

/**
 * Streams {@see ReportRecord}s into ClickHouse. The
 * target table is a ReplacingMergeTree keyed on `event_id`, so re-writing the same
 * event (at-least-once outbox delivery) collapses on merge — this sink does no
 * write-side dedup of its own.
 *
 * It fails open: a ClickHouse outage is swallowed so event delivery is never blocked
 * by an analytics write.
 */
class ClickHouseReportSink implements ReportSink
{
    public function __construct(
        private readonly ClickHouseConnection $connection,
        private readonly string $table,
    ) {}

    public function write(array $records): void
    {
        if ($records === []) {
            return;
        }

        $rows = [];
        foreach ($records as $record) {
            $rows[] = $record->toRow();
        }

        try {
            $this->connection->insert($this->table, $rows);
        } catch (Throwable) {
            // Fail open: never let an analytics write break event delivery.
        }
    }
}
