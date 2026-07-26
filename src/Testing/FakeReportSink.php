<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Testing;

use Cbox\Id\Analytics\Contracts\ReportSink;
use Cbox\Id\Analytics\ValueObjects\ReportRecord;

/**
 * An assertable {@see ReportSink} for tests and local development. It keeps the
 * records handed to it keyed by `eventId` — so, exactly like the ClickHouse
 * ReplacingMergeTree it stands in for, re-delivering the same event collapses to a
 * single stored record — while also counting raw write invocations so a test can
 * prove at-least-once delivery actually happened more than once.
 */
class FakeReportSink implements ReportSink
{
    /**
     * @var array<string, ReportRecord>
     */
    private array $records = [];

    private int $writeCalls = 0;

    private int $recordCalls = 0;

    public function write(array $records): void
    {
        $this->writeCalls++;

        foreach ($records as $record) {
            $this->recordCalls++;
            // Keyed on the event id: a re-delivered event overwrites its own row.
            $this->records[$record->eventId] = $record;
        }
    }

    /**
     * The de-duplicated records, in first-seen order.
     *
     * @return list<ReportRecord>
     */
    public function records(): array
    {
        return array_values($this->records);
    }

    public function first(): ?ReportRecord
    {
        return $this->records()[0] ?? null;
    }

    public function forEvent(string $eventId): ?ReportRecord
    {
        return $this->records[$eventId] ?? null;
    }

    /**
     * How many distinct events were recorded (post-dedup).
     */
    public function count(): int
    {
        return count($this->records);
    }

    /**
     * How many records were handed to the sink in total (pre-dedup) — a re-delivered
     * event counts twice here but once in {@see count()}.
     */
    public function received(): int
    {
        return $this->recordCalls;
    }

    /**
     * How many times {@see write()} was invoked.
     */
    public function writes(): int
    {
        return $this->writeCalls;
    }
}
