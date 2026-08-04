<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Console;

use Cbox\Id\Compliance\Retention\RetentionPolicy;
use Cbox\Id\Compliance\Retention\RetentionReport;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Console\Command;

/**
 * Applies the retention policy: signs a fresh checkpoint on every audit chain, so the
 * history the export sink has archived stays externally verifiable. NOTHING is deleted —
 * the trail is append-only and hash-chained, and removing a row is precisely what
 * `verifyChain` exists to detect ({@see RetentionPolicy}).
 *
 * There was no command at all. `RetentionPolicy::apply()` shipped, was tested, was
 * described in the config and the console — and its only callers repo-wide were two
 * tests, so no deployment ever anchored a chain it had not anchored by hand.
 *
 * Runs PER ENVIRONMENT, explicitly, for the same reason {@see ExportAuditCommand} does:
 * checkpoints are environment-owned and nothing establishes an environment on the command
 * line, so an unscoped run would checkpoint nothing and report success.
 */
class ApplyRetentionCommand extends Command
{
    protected $signature = 'id-compliance:retention {--environment= : Apply to one environment by slug, instead of every environment}';

    protected $description = 'Checkpoint and anchor every Cbox ID audit chain (append-only retention — never deletes).';

    public function handle(RetentionPolicy $policy, EnvironmentContext $context): int
    {
        $environments = $this->environments();

        if ($environments === []) {
            // A named environment that does not exist is a FAILURE. A scheduled run
            // carrying a stale slug must not exit 0 having anchored nothing — the same
            // rule the export command holds, and for the same reason: silence is the one
            // outcome a compliance job may never report as success.
            if ($this->option('environment') !== null) {
                return self::FAILURE;
            }

            $this->warn('No environment to apply retention to. Nothing was anchored.');

            return self::SUCCESS;
        }

        foreach ($environments as $environment) {
            /** @var RetentionReport $report */
            $report = $context->runAs($environment, fn (): RetentionReport => $policy->apply());

            $this->line(sprintf(
                '[%s] checkpointed %d chain(s); %d entrie(s) deleted (always zero — retention anchors, it does not erase).',
                $environment->slug,
                $report->checkpointCount(),
                $report->entriesDeleted,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * The environments this invocation covers. Read without the global scope: there is no
     * ambient environment on the command line, so a scoped read returns nothing.
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
