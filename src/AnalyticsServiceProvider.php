<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics;

use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Analytics\ClickHouse\ClickHouseConnection;
use Cbox\Id\Analytics\Console\AnalyticsInstallCommand;
use Cbox\Id\Analytics\Contracts\ReportReader;
use Cbox\Id\Analytics\Contracts\ReportSink;
use Cbox\Id\Analytics\Listeners\StreamDomainEventToSink;
use Cbox\Id\Analytics\Readers\ClickHouseReportReader;
use Cbox\Id\Analytics\Readers\UsageMeterReportReader;
use Cbox\Id\Analytics\Sinks\ClickHouseReportSink;
use Cbox\Id\Analytics\Sinks\NullReportSink;
use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;
use Throwable;

/**
 * The Cbox ID analytics plugin. Installed alongside laravel-id and a console-kit
 * host, it streams the platform's transactional-outbox domain events (the public
 * {@see EventDelivered} seam) into a pluggable {@see ReportSink}, and lights up an
 * Analytics console reading through a {@see ReportReader} — all with zero edits to
 * the host.
 *
 * ClickHouse is referenced ONLY behind those contracts and ONLY when a DSN is
 * configured; with no DSN the sink is inert (events go nowhere) and the dashboards
 * read the platform's own Postgres usage counters, so the open framework stays
 * ClickHouse-free.
 */
class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/id-analytics.php', 'id-analytics');

        // Inert-until-wired defaults: events stream to a no-op sink and dashboards
        // read the platform's own usage counters (works self-hosted, no ClickHouse).
        $this->app->bindIf(ReportSink::class, NullReportSink::class);
        $this->app->bindIf(ReportReader::class, UsageMeterReportReader::class);

        // Switch the sink + reader over to ClickHouse only when a DSN is present.
        if ($this->configString('id-analytics.clickhouse.dsn') !== '') {
            $this->registerClickHouse();
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'id-analytics');
        Volt::mount([__DIR__.'/../resources/views/livewire']);
        $this->loadRoutesFrom(__DIR__.'/../routes/analytics.php');

        // The plugin hook: project every delivered outbox event onto the bound sink.
        $this->app->make(Dispatcher::class)->listen(EventDelivered::class, StreamDomainEventToSink::class);

        // Console — present when a real sink is wired or analytics is explicitly on.
        Console::features()->register('analytics', fn (): bool => $this->analyticsActive());

        Console::nav()->area('analytics', 'Analytics', 'chart', 55)
            ->page('analytics.overview', 'Overview', feature: 'analytics', order: 10);

        Console::dashboardCard(fn (): string => $this->loginsCard(), 4);

        if ($this->app->runningInConsole()) {
            $this->commands([AnalyticsInstallCommand::class]);
        }
    }

    private function registerClickHouse(): void
    {
        $this->app->singleton(ClickHouseConnection::class, fn (Application $app): ClickHouseConnection => new ClickHouseConnection(
            $app->make(HttpFactory::class),
            $this->configString('id-analytics.clickhouse.dsn'),
            $this->configString('id-analytics.clickhouse.database', 'default'),
            $this->configString('id-analytics.clickhouse.user', 'default'),
            $this->configString('id-analytics.clickhouse.password'),
            $this->configInt('id-analytics.clickhouse.timeout', 5),
        ));

        $this->app->bind(ReportSink::class, fn (Application $app): ClickHouseReportSink => new ClickHouseReportSink(
            $app->make(ClickHouseConnection::class),
            $this->configString('id-analytics.clickhouse.table', 'id_analytics_events'),
        ));

        $this->app->bind(ReportReader::class, fn (Application $app): ClickHouseReportReader => new ClickHouseReportReader(
            $app->make(ClickHouseConnection::class),
            $app->make(EnvironmentContext::class),
            $this->configString('id-analytics.clickhouse.table', 'id_analytics_events'),
        ));
    }

    private function analyticsActive(): bool
    {
        $sinkIsReal = ! $this->app->make(ReportSink::class) instanceof NullReportSink;

        return $sinkIsReal || (bool) config('id-analytics.enabled', false);
    }

    /**
     * Dashboard card: logins in the last day. Empty (nothing rendered) if the reader
     * can't be read yet (no schema / no environment) — never a broken dashboard.
     */
    private function loginsCard(): string
    {
        try {
            $reader = $this->app->make(ReportReader::class);
            $until = Carbon::now();
            $logins = $reader->snapshot(null, $until->copy()->subDay(), $until)['auth.login'] ?? 0;
        } catch (Throwable) {
            return '';
        }

        return $this->app->make(ViewFactory::class)
            ->make('id-analytics::components.analytics-card', ['logins' => $logins])
            ->render();
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
