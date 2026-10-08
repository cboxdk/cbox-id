<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs\Jobs;

use App\Models\AuditLogs\AuditLogExport;
use App\Platform\AuditLogs\AuditLogCsv;
use App\Platform\AuditLogs\AuditLogExports;
use App\Platform\AuditLogs\AuditLogFilters;
use App\Platform\AuditLogs\AuditLogQuery;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Writes one {@see AuditLogExport}'s CSV.
 *
 * A worker has no environment, and the export and the events are environment-owned — so,
 * as the platform's own stream pump does, it learns the export's environment with one
 * system read and re-enters exactly that environment before reading anything else. Inside
 * it, every query is held to that environment by the hard scope, and the export's own
 * filters (its organization among them) bound the rows: an export can only ever contain
 * what the person who asked for it could list.
 *
 * Streamed to a temporary file a keyset page at a time ({@see AuditLogQuery::each()}), so
 * memory stays flat however large the export is, then put on the export disk.
 */
final class GenerateAuditLogExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly string $exportId) {}

    public function handle(EnvironmentContext $context): void
    {
        $environmentId = $context->withoutScope(
            fn (): mixed => AuditLogExport::query()->whereKey($this->exportId)->value('environment_id'),
        );

        if (! is_string($environmentId) || $environmentId === '') {
            return;
        }

        $context->runAs(GenericEnvironment::of($environmentId), function () use ($environmentId): void {
            $export = AuditLogExport::query()->whereKey($this->exportId)->first();

            if ($export === null || $export->state !== AuditLogExport::PENDING) {
                return;
            }

            try {
                $this->write($export, $environmentId);
            } catch (Throwable $e) {
                $export->forceFill([
                    'state' => AuditLogExport::FAILED,
                    'error' => 'The export could not be written. Ask for a new one; if it fails again, narrow its range.',
                    'completed_at' => Carbon::now(),
                ])->save();

                report($e);
            }
        });
    }

    private function write(AuditLogExport $export, string $environmentId): void
    {
        $filters = AuditLogFilters::from($export->filters, $export->organization_id);
        $handle = tmpfile();

        if ($handle === false) {
            throw new RuntimeException('No temporary file for the export.');
        }

        try {
            $rows = AuditLogCsv::write($handle, AuditLogQuery::each($filters, AuditLogExports::MAX_ROWS));
            rewind($handle);

            $path = "audit-log-exports/{$environmentId}/{$export->id}.csv";
            AuditLogExports::disk()->writeStream($path, $handle);
        } finally {
            fclose($handle);
        }

        $export->forceFill([
            'state' => AuditLogExport::READY,
            'row_count' => $rows,
            'path' => $path,
            'completed_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHours(AuditLogExports::ttlHours()),
        ])->save();
    }
}
