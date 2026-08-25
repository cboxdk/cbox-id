<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance;

use App\Platform\Console\ConsoleArea;
use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\Compliance\Console\ApplyRetentionCommand;
use Cbox\Id\Compliance\Console\ExportAuditCommand;
use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\Export\ExportAuditTrail;
use Cbox\Id\Compliance\Models\AuditExportCursor;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Cbox\Id\Compliance\Retention\RetentionPolicy;
use Cbox\Id\Compliance\Sinks\HttpSiemExportSink;
use Cbox\Id\Compliance\Sinks\JsonlBundleExportSink;
use Cbox\Id\Compliance\Sinks\NullAuditExportSink;
use Cbox\Id\Devices\DevicesServiceProvider;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;
use Throwable;

/**
 * The Cbox ID compliance module. It exports the platform's append-only, hash-chained audit trail — read
 * through the existing {@see AuditReader} pull cursor — to a pluggable
 * {@see AuditExportSink}, and lights up a Compliance console (audit search + chain
 * verification, export runs, honest append-only retention, and a data-subject
 * export) — all with zero edits to the host.
 *
 * A SIEM, object store or cold archive is referenced ONLY behind the sink contract
 * and ONLY when configured; with the default `null` sink the plugin is inert
 * (nothing is exported, the cursor never moves), so installing without wiring a
 * destination is safe and the open framework stays free of any SIEM dependency.
 *
 * Vendored in-tree under modules/, but it still registers itself the way an external
 * package would — its own provider, nav, routes, views and gates through the public
 * console-kit sockets, with no edit to app/. That is deliberate: a first-party module
 * that needed a private hook would make the extension point a fiction.
 */
class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Inert-until-wired default: no destination, so the engine exports nothing.
        $this->app->bindIf(AuditExportSink::class, NullAuditExportSink::class);

        // Swap in a real sink only when one is configured.
        $this->registerConfiguredSink();

        $this->app->bind(ExportAuditTrail::class, fn (Application $app): ExportAuditTrail => new ExportAuditTrail(
            $app->make(AuditReader::class),
            $app->make(AuditExportSink::class),
            $this->configInt('compliance.export.batch_size', 500),
        ));

        $this->app->bind(RetentionPolicy::class, fn (Application $app): RetentionPolicy => new RetentionPolicy(
            $app->make(AuditLog::class),
            (bool) config('compliance.retention.checkpoint_on_apply', true),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'id-compliance');
        Volt::mount([__DIR__.'/../resources/views/livewire']);
        $this->loadRoutesFrom(__DIR__.'/../routes/compliance.php');

        // Console — present when a real sink is wired or compliance is explicitly on.
        Console::features()->register('compliance', fn (): bool => $this->complianceActive());

        // Appended to the host's Logs area rather than a "Compliance" area of its own.
        // These pages are the same subject as Activity log — the audit trail — viewed
        // with chain verification and export; two areas meant an admin looking for
        // "the log" had to guess which of them held the one they wanted. The Exports
        // label carries the page's full title, as every nav entry must.
        // Through ConsolePages, which serves BOTH planes by default. The old call went to
        // the organization rail's registry and nowhere else, so the environment
        // administrator — the person a regulator actually asks — had neither page.
        $pages = $this->app->make(ConsolePages::class);

        $pages->add(
            area: ConsoleArea::Logs,
            route: 'compliance.audit',
            label: 'Audit trail',
            feature: 'compliance',
            order: 20,
        );

        $pages->add(
            area: ConsoleArea::Logs,
            route: 'compliance.data-exports',
            label: 'Exports & retention',
            feature: 'compliance',
            order: 30,
        );

        Console::dashboardCard(fn (): string => $this->exportCard(), 8);

        if ($this->app->runningInConsole()) {
            $this->commands([ExportAuditCommand::class, ApplyRetentionCommand::class]);
        }

        $this->scheduleComplianceWork();
    }

    /**
     * Put the engine on the schedule — the step that was missing, and without which the
     * whole module was decoration.
     *
     * `id-compliance:export` was registered as a command and scheduled NOWHERE, and there
     * was no retention command to schedule at all. So an operator could point
     * `CBOX_ID_COMPLIANCE_SINK=http` at their SIEM, watch the module report itself active
     * and the dashboard card show a pending backlog, and never have one audit entry
     * shipped — while believing the pipeline was live. That is the module's entire value
     * proposition, and it could not run in any deployment.
     *
     * Registered here rather than in the host's `routes/console.php`, which this module
     * does not edit — the same socket {@see DevicesServiceProvider} uses.
     * The activity check is INSIDE the callback so it reads config at schedule-resolution
     * time and resolves the sink no earlier than it must.
     */
    private function scheduleComplianceWork(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // With the inert null sink the export writes a run row per environment and
            // ships nothing, so an install that never wired compliance would collect
            // bookkeeping for work it did not ask for. Same gate the console uses.
            if (! $this->complianceActive()) {
                return;
            }

            // Minutes, not hours: the backlog between runs is audit evidence sitting only
            // in this database, and a SIEM pipeline that lags an hour is one an incident
            // responder cannot use. Idempotent and cursor-based, so a run that overlaps a
            // slow one is skipped rather than re-shipping.
            if ((bool) config('compliance.schedule.export', true)) {
                $schedule->command(ExportAuditCommand::class)
                    ->everyFiveMinutes()
                    ->name('cbox-id:compliance:export')
                    ->withoutOverlapping()
                    ->onOneServer();
            }

            // Daily is enough: this signs a checkpoint per chain, which is an anchor for
            // history already written, not a race with new entries.
            if ((bool) config('compliance.schedule.retention', true)) {
                $schedule->command(ApplyRetentionCommand::class)
                    ->daily()
                    ->name('cbox-id:compliance:retention')
                    ->withoutOverlapping()
                    ->onOneServer();
            }
        });
    }

    private function registerConfiguredSink(): void
    {
        $driver = $this->configString('compliance.export.sink', 'null');

        if ($driver === 'jsonl') {
            $this->app->bind(AuditExportSink::class, fn (Application $app): JsonlBundleExportSink => new JsonlBundleExportSink(
                $app->make(FilesystemFactory::class)->disk($this->configString('compliance.export.jsonl.disk', 'local')),
                $this->configString('compliance.export.jsonl.path', 'compliance/audit'),
            ));

            return;
        }

        if ($driver === 'http' && $this->configString('compliance.export.siem.endpoint') !== '') {
            // No injected HTTP factory: the sink goes through Http::ssrf(), which
            // resolves the guard from the active container at call time so it survives a
            // container rebuild.
            $this->app->bind(AuditExportSink::class, fn (Application $app): HttpSiemExportSink => new HttpSiemExportSink(
                $this->configString('compliance.export.siem.endpoint'),
                $this->configString('compliance.export.siem.token'),
                $this->configInt('compliance.export.siem.timeout', 10),
            ));
        }
    }

    private function complianceActive(): bool
    {
        return ! $this->app->make(AuditExportSink::class)->isInert()
            || (bool) config('compliance.enabled', false);
    }

    /**
     * Dashboard card: entries still pending export, for the ACTING ORGANIZATION's own
     * audit chain. Empty (nothing rendered) before migrations run or when the trail
     * can't be read — never a broken dashboard.
     *
     * The count used to sum EVERY chain in the environment, so a tenant admin's own
     * dashboard reported the size of every other tenant's audit backlog, and the last
     * run's status beside it. The page this card links to already draws the line
     * exactly here and says why: a run row has no organization — one run walks every
     * chain in the environment — so the run history is shown to the plane that owns the
     * environment and withheld from the plane that owns one tenant. The card is now the
     * same rule in a smaller frame.
     */
    private function exportCard(): string
    {
        try {
            $scope = $this->app->make(ConsoleScope::class);
            $organizationId = $scope->organizationId();

            if ($organizationId === null) {
                return '';
            }

            $pending = $this->pendingEntryCount($organizationId);

            // Environment bookkeeping, on the plane that owns the environment. Read at
            // all only there, so a tenant's dashboard cannot report on work done across
            // every tenant.
            $lastRun = $scope->plane() === ConsolePlane::Environment
                ? AuditExportRun::query()->latest('id')->first()
                : null;
        } catch (Throwable) {
            return '';
        }

        return $this->app->make(ViewFactory::class)
            ->make('id-compliance::components.compliance-card', [
                'pending' => $pending,
                'lastRun' => $lastRun,
                'showsRuns' => $scope->plane() === ConsolePlane::Environment,
            ])
            ->render();
    }

    /**
     * How many of this organization's recorded entries have not yet been shipped.
     * Sequences are contiguous per scope, so head − cursor is an exact backlog.
     *
     * A chain is addressed by (environment, scope) and the scope of an organization's
     * chain IS its id — the framework's audit log writes `organizationId ?? '__system__'`
     * into that column — so one organization's backlog is one head minus one cursor. No
     * grouping, and nothing summed across tenants.
     */
    private function pendingEntryCount(string $organizationId): int
    {
        $head = AuditEntry::query()
            ->where('scope', $organizationId)
            ->max('sequence');

        $exported = AuditExportCursor::query()
            ->where('scope', $organizationId)
            ->value('last_sequence');

        return max(0, $this->toInt($head) - $this->toInt($exported));
    }

    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    private function configString(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }
}
