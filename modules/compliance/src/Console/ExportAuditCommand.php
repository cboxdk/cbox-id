<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Console;

use Cbox\Id\Compliance\Export\ExportAuditTrail;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Console\Command;

/**
 * Ships newly-recorded audit entries to the configured export sink. Idempotent and
 * resumable (cursor-based), so it is safe to run on a schedule — e.g. every few
 * minutes from the host's console kernel. With the inert null sink it is a no-op.
 *
 * Runs PER ENVIRONMENT, explicitly. The export run and its cursor are both
 * environment-owned, and nothing establishes an environment context on the command
 * line — every setter in this application is HTTP middleware. So the command wrote a
 * row with no `environment_id` and died on the NOT NULL constraint before reaching its
 * own error handling, on every run; and had that write succeeded, the cursor read would
 * have been scoped to nothing, restarting from sequence zero and re-shipping the whole
 * trail each time. Neither surfaced in testing, because the suite pins an environment
 * for every test and the console page that calls the same service runs inside a request
 * that already has one.
 */
class ExportAuditCommand extends Command
{
    protected $signature = 'id-compliance:export {--environment= : Export one environment by slug, instead of every environment}';

    protected $description = 'Export new Cbox ID audit-trail entries to the configured sink (SIEM / JSONL archive).';

    public function handle(ExportAuditTrail $export, EnvironmentContext $context): int
    {
        $environments = $this->environments();

        if ($environments === []) {
            $this->warn('No environment to export. Nothing was written.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($environments as $environment) {
            /** @var AuditExportRun $run */
            $run = $context->runAs($environment, fn (): AuditExportRun => $export->run());

            $this->line(sprintf(
                '[%s] exported %d entrie(s) in %d batch(es) across %d scope(s) via %s.',
                $environment->slug,
                $run->entries_exported,
                $run->batches,
                $run->scopes_scanned,
                $run->sink ?? 'none',
            ));

            if ($run->status === AuditExportRun::STATUS_FAILED) {
                // Reported per environment, and the loop continues: one tenant's
                // unreachable SIEM must not stop every other tenant's trail shipping.
                $this->warn(sprintf(
                    '[%s] completed with errors (cursor held; entries retry next run): %s',
                    $environment->slug,
                    $run->error ?? 'unknown error',
                ));

                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The environments this invocation covers.
     *
     * Read without the global scope on purpose: there is no ambient environment on the
     * command line, so a scoped read returns nothing — which is the same shape that made
     * the previous version silently wrong in the read direction.
     *
     * @return list<Environment>
     */
    private function environments(): array
    {
        $slug = $this->option('environment');

        $query = Environment::query()->withoutGlobalScopes()->orderBy('slug');

        if (is_string($slug) && $slug !== '') {
            $query->where('slug', $slug);
        }

        /** @var list<Environment> $environments */
        $environments = $query->get()->all();

        if ($environments === [] && is_string($slug) && $slug !== '') {
            $this->error(sprintf('No environment with slug "%s".', $slug));
        }

        return $environments;
    }
}
