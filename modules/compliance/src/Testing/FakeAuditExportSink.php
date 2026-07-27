<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Testing;

use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\ValueObjects\AuditExportBatch;
use Cbox\Id\Compliance\ValueObjects\AuditExportRecord;

/**
 * An assertable {@see AuditExportSink} for tests and local development. It keeps
 * every batch handed to it and flattens their records, so a test can prove exactly
 * which entries were shipped (and that a resumed run ships only the new ones).
 */
class FakeAuditExportSink implements AuditExportSink
{
    /** @var list<AuditExportBatch> */
    private array $batches = [];

    public function export(AuditExportBatch $batch): void
    {
        $this->batches[] = $batch;
    }

    /**
     * @return list<AuditExportBatch>
     */
    public function batches(): array
    {
        return $this->batches;
    }

    /**
     * Every exported record, in the order shipped.
     *
     * @return list<AuditExportRecord>
     */
    public function records(): array
    {
        $records = [];

        foreach ($this->batches as $batch) {
            foreach ($batch->records as $record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Exported records for one scope only.
     *
     * @return list<AuditExportRecord>
     */
    public function forScope(string $scope): array
    {
        return array_values(array_filter(
            $this->records(),
            static fn (AuditExportRecord $record): bool => $record->scope === $scope,
        ));
    }

    /**
     * The total number of records shipped across every batch.
     */
    public function count(): int
    {
        return count($this->records());
    }

    /**
     * How many times {@see export()} was invoked.
     */
    public function batchCount(): int
    {
        return count($this->batches);
    }
}
