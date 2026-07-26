<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Console;

use Cbox\Id\Compliance\Export\ExportAuditTrail;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Illuminate\Console\Command;

/**
 * Ships newly-recorded audit entries to the configured export sink. Idempotent and
 * resumable (cursor-based), so it is safe to run on a schedule — e.g. every few
 * minutes from the host's console kernel. With the inert null sink it is a no-op.
 */
class ExportAuditCommand extends Command
{
    protected $signature = 'id-compliance:export';

    protected $description = 'Export new Cbox ID audit-trail entries to the configured sink (SIEM / JSONL archive).';

    public function handle(ExportAuditTrail $export): int
    {
        $run = $export->run();

        $this->line(sprintf(
            'Exported %d entrie(s) in %d batch(es) across %d scope(s) via %s.',
            $run->entries_exported,
            $run->batches,
            $run->scopes_scanned,
            $run->sink ?? 'none',
        ));

        if ($run->status === AuditExportRun::STATUS_FAILED) {
            $this->warn('Export completed with errors (cursor held for affected scopes; entries will retry next run): '.($run->error ?? 'unknown error'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
