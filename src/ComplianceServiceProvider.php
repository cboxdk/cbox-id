<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance;

use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\Compliance\Console\ExportAuditCommand;
use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\Export\ExportAuditTrail;
use Cbox\Id\Compliance\Models\AuditExportCursor;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Cbox\Id\Compliance\Retention\RetentionPolicy;
use Cbox\Id\Compliance\Sinks\HttpSiemExportSink;
use Cbox\Id\Compliance\Sinks\JsonlBundleExportSink;
use Cbox\Id\Compliance\Sinks\NullAuditExportSink;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;
use Throwable;

/**
 * The Cbox ID compliance plugin. Installed alongside laravel-id and a console-kit
 * host, it exports the platform's append-only, hash-chained audit trail — read
 * through the existing {@see AuditReader} pull cursor — to a pluggable
 * {@see AuditExportSink}, and lights up a Compliance console (audit search + chain
 * verification, export runs, honest append-only retention, and a data-subject
 * export) — all with zero edits to the host.
 *
 * A SIEM, object store or cold archive is referenced ONLY behind the sink contract
 * and ONLY when configured; with the default `null` sink the plugin is inert
 * (nothing is exported, the cursor never moves), so installing without wiring a
 * destination is safe and the open framework stays free of any SIEM dependency.
 */
final class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/compliance.php', 'compliance');

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

        Console::nav()->area('compliance', 'Compliance', 'shield-check', 75)
            ->page('compliance.audit', 'Audit trail', feature: 'compliance', order: 10)
            ->page('compliance.exports', 'Exports', feature: 'compliance', order: 20);

        Console::dashboardCard(fn (): string => $this->exportCard(), 8);

        if ($this->app->runningInConsole()) {
            $this->commands([ExportAuditCommand::class]);
        }
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
            $this->app->bind(AuditExportSink::class, fn (Application $app): HttpSiemExportSink => new HttpSiemExportSink(
                $app->make(HttpFactory::class),
                $this->configString('compliance.export.siem.endpoint'),
                $this->configString('compliance.export.siem.token'),
                $this->configInt('compliance.export.siem.timeout', 10),
            ));
        }
    }

    private function complianceActive(): bool
    {
        $sinkIsReal = ! $this->app->make(AuditExportSink::class) instanceof NullAuditExportSink;

        return $sinkIsReal || (bool) config('compliance.enabled', false);
    }

    /**
     * Dashboard card: entries still pending export and the last run's status. Empty
     * (nothing rendered) before migrations run or when the trail can't be read —
     * never a broken dashboard.
     */
    private function exportCard(): string
    {
        try {
            $pending = $this->pendingEntryCount();
            $lastRun = AuditExportRun::query()->latest('id')->first();
        } catch (Throwable) {
            return '';
        }

        return $this->app->make(ViewFactory::class)
            ->make('id-compliance::components.compliance-card', [
                'pending' => $pending,
                'lastRun' => $lastRun,
            ])
            ->render();
    }

    /**
     * How many recorded entries have not yet been shipped, summed across chains.
     * Sequences are contiguous per scope, so head − cursor is an exact backlog.
     */
    private function pendingEntryCount(): int
    {
        $heads = AuditEntry::query()
            ->selectRaw('scope, MAX(sequence) as head')
            ->groupBy('scope')
            ->pluck('head', 'scope');

        $cursors = AuditExportCursor::query()->pluck('last_sequence', 'scope');

        $pending = 0;

        foreach ($heads as $scope => $head) {
            $exported = $cursors->get($scope, 0);
            $pending += max(0, $this->toInt($head) - $this->toInt($exported));
        }

        return $pending;
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
