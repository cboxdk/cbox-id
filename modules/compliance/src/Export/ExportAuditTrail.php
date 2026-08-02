<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Export;

use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\Models\AuditExportCursor;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Cbox\Id\Compliance\Sinks\NullAuditExportSink;
use Cbox\Id\Compliance\Support\AuditScopes;
use Cbox\Id\Compliance\ValueObjects\AuditExportBatch;
use Cbox\Id\Compliance\ValueObjects\AuditExportRecord;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The export engine. For each chain (scope) it reads new entries incrementally from
 * the persisted cursor via {@see AuditReader::since()}, ships them to the bound
 * {@see AuditExportSink} in batches, and advances the cursor only after a batch is
 * accepted — so a run is idempotent and resumable, and re-running never re-ships an
 * already-exported entry.
 *
 * It is fail-open per run: if a scope's sink throws, that scope's cursor is held
 * (its entries are re-offered next run) and the engine moves on, recording the run
 * as failed rather than aborting the process or corrupting other scopes' progress.
 */
class ExportAuditTrail
{
    private const SYSTEM_SCOPE = '__system__';

    public function __construct(
        private readonly AuditReader $reader,
        private readonly AuditExportSink $sink,
        private readonly int $batchSize = 500,
    ) {}

    public function run(): AuditExportRun
    {
        $startedAt = Carbon::now();

        // Inert until wired: with the null sink there is no destination, so we do
        // NOT advance any cursor — otherwise wiring a real sink later would skip
        // every entry recorded in the meantime. Nothing is scanned or shipped.
        if ($this->sink instanceof NullAuditExportSink) {
            return $this->record(AuditExportRun::STATUS_COMPLETED, 0, 0, 0, null, $startedAt);
        }

        $batchSize = max(1, $this->batchSize);

        $entriesExported = 0;
        $batches = 0;
        $scopes = AuditScopes::all();
        $firstError = null;

        foreach ($scopes as $organizationId) {
            try {
                $result = $this->exportScope($organizationId, $batchSize);
                $entriesExported += $result[0];
                $batches += $result[1];
            } catch (Throwable $e) {
                // Fail open: hold this scope's cursor and keep going. The unshipped
                // entries are re-offered on the next run.
                $firstError ??= $e->getMessage();
            }
        }

        return $this->record(
            $firstError === null ? AuditExportRun::STATUS_COMPLETED : AuditExportRun::STATUS_FAILED,
            count($scopes),
            $entriesExported,
            $batches,
            $firstError,
            $startedAt,
        );
    }

    private function record(string $status, int $scopesScanned, int $entriesExported, int $batches, ?string $error, Carbon $startedAt): AuditExportRun
    {
        $run = new AuditExportRun;
        $run->fill([
            'status' => $status,
            'scopes_scanned' => $scopesScanned,
            'entries_exported' => $entriesExported,
            'batches' => $batches,
            'sink' => $this->sink::class,
            'error' => $error,
            'started_at' => $startedAt,
            'finished_at' => Carbon::now(),
        ]);
        $run->save();

        return $run;
    }

    /**
     * Ship one scope's backlog. Returns [entriesExported, batchesShipped].
     *
     * @return array{0: int, 1: int}
     */
    private function exportScope(?string $organizationId, int $batchSize): array
    {
        $scope = $organizationId ?? self::SYSTEM_SCOPE;
        $cursor = $this->cursorFor($scope, $organizationId);

        $exported = 0;
        $batches = 0;

        while (true) {
            $entries = $this->reader->since($organizationId, $cursor->last_sequence, $batchSize);

            if ($entries === []) {
                break;
            }

            $records = array_map(
                static fn (AuditEntry $entry): AuditExportRecord => AuditExportRecord::fromEntry($entry),
                $entries,
            );

            $from = $records[0]->sequence;
            $to = $records[count($records) - 1]->sequence;

            // May throw: the cursor is advanced only if this returns cleanly.
            // The environment comes from the records themselves, not from ambient
            // context: they are all one environment's by construction (the read is
            // scoped), and taking it from the data means the batch cannot disagree with
            // what is in it.
            $this->sink->export(new AuditExportBatch(
                $records[0]->environmentId,
                $scope,
                $organizationId,
                $records,
                $from,
                $to,
            ));

            $cursor->last_sequence = $to;
            $cursor->save();

            $exported += count($records);
            $batches++;

            if (count($entries) < $batchSize) {
                break;
            }
        }

        return [$exported, $batches];
    }

    private function cursorFor(string $scope, ?string $organizationId): AuditExportCursor
    {
        $cursor = AuditExportCursor::query()->firstOrNew(
            ['scope' => $scope],
            ['last_sequence' => 0],
        );
        $cursor->organization_id = $organizationId;

        return $cursor;
    }
}
