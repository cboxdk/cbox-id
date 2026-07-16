<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Contracts;

use Cbox\Id\Analytics\Sinks\NullReportSink;
use Cbox\Id\Analytics\ValueObjects\ReportRecord;

/**
 * The destination analytics events are streamed to. The plugin ships a
 * {@see NullReportSink} default so it is inert until an
 * operator wires a real sink (ClickHouse, via config) — the open framework never
 * references a column-store directly, it only speaks this contract.
 *
 * Implementations MUST be idempotent on {@see ReportRecord::$eventId}: outbox
 * delivery is at-least-once, so the same record can be written more than once and
 * the sink is responsible for collapsing it (ClickHouse does this with a
 * ReplacingMergeTree keyed on the event id).
 *
 * Implementations SHOULD fail open — a sink outage must degrade to "no analytics",
 * never a failed event delivery.
 */
interface ReportSink
{
    /**
     * Persist a batch of records. A write error should be swallowed, not thrown.
     *
     * @param  list<ReportRecord>  $records
     */
    public function write(array $records): void;
}
