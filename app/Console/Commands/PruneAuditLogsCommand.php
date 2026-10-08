<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLogs\AuditLogChain;
use App\Models\AuditLogs\AuditLogExport;
use App\Platform\AuditLogs\AuditLogPruner;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Console\Command;

/**
 * Apply every environment's audit-log retention, and delete expired export files.
 *
 * Scheduled daily (routes/console.php). The scheduler has no environment, and the events
 * are environment-owned: so the command reads WHICH environments hold audit logs with one
 * system read — the chain heads and exports, a row per organization or export, never the
 * events — and runs the prune inside each in turn, where the environment's own retention
 * applies and the hard scope holds every query to it.
 */
class PruneAuditLogsCommand extends Command
{
    protected $signature = 'audit-logs:prune';

    protected $description = 'Delete audit events past each environment’s retention, and expired audit-log exports';

    public function handle(EnvironmentContext $context, AuditLogPruner $pruner): int
    {
        /** @var list<string> $environments */
        $environments = $context->withoutScope(static fn (): array => array_values(array_unique(array_filter(
            [
                ...AuditLogChain::query()->distinct()->pluck('environment_id')->all(),
                ...AuditLogExport::query()->distinct()->pluck('environment_id')->all(),
            ],
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ))));

        $events = 0;
        $exports = 0;

        foreach ($environments as $environmentId) {
            $result = $context->runAs(GenericEnvironment::of($environmentId), static fn (): array => $pruner->prune());
            $events += $result['events'];
            $exports += $result['exports'];
        }

        $this->components->info("Pruned {$events} audit events and expired {$exports} exports across ".count($environments).' environments.');

        return self::SUCCESS;
    }
}
