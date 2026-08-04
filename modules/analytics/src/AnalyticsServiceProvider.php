<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics;

use App\Platform\Console\ConsoleArea;
use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsoleScope;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Analytics\ClickHouse\ClickHouseConnection;
use Cbox\Id\Analytics\Console\AnalyticsInstallCommand;
use Cbox\Id\Analytics\Contracts\ReportReader;
use Cbox\Id\Analytics\Contracts\ReportSink;
use Cbox\Id\Analytics\Listeners\StreamDomainEventToSink;
use Cbox\Id\Analytics\Readers\ClickHouseReportReader;
use Cbox\Id\Analytics\Readers\DatabaseReportReader;
use Cbox\Id\Analytics\Readers\UsageMeterReportReader;
use Cbox\Id\Analytics\Sinks\ClickHouseReportSink;
use Cbox\Id\Analytics\Sinks\DatabaseReportSink;
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
 * The Cbox ID analytics module. It streams the platform's transactional-outbox domain events (the public
 * {@see EventDelivered} seam) into a pluggable {@see ReportSink}, and lights up an
 * Analytics console reading through a {@see ReportReader} — all with zero edits to
 * the host.
 *
 * Three stores sit behind those contracts, chosen by config and always swapped as a
 * MATCHED pair so the dashboards read back what the sink wrote: nothing (the default
 * — events are discarded and the dashboards read the platform's own usage counters),
 * the app's own database, or ClickHouse. ClickHouse is referenced ONLY when a DSN is
 * configured, so an installation that has none never speaks to one.
 *
 * Vendored in-tree under modules/, but it still registers itself the way an external
 * package would — its own provider, nav, routes, views and gates through the public
 * console-kit sockets, with no edit to app/. That is deliberate: a first-party module
 * that needed a private hook would make the extension point a fiction.
 */
class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Inert-until-wired defaults: events stream to a no-op sink and dashboards
        // read the platform's own usage counters (works self-hosted, no ClickHouse).
        $this->app->bindIf(ReportSink::class, NullReportSink::class);
        $this->app->bindIf(ReportReader::class, UsageMeterReportReader::class);

        // A ClickHouse DSN wins; otherwise the relational store is available as an
        // opt-in. Both replace the inert pair above as a MATCHED set — a sink and a
        // reader over the same store — so the dashboards always read back what the
        // sink just wrote, rather than one store's writes and another's reads.
        if ($this->configString('id-analytics.clickhouse.dsn') !== '') {
            $this->registerClickHouse();
        } elseif ($this->configString('id-analytics.store') === 'database') {
            $this->registerDatabaseStore();
        }
    }

    public function boot(): void
    {
        // Loaded unconditionally, not only when the relational store is switched on:
        // a table that exists and stays empty costs nothing, while a schema that
        // appears only under one config value means enabling the store on a live
        // deployment needs a migration run nobody scheduled.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'id-analytics');
        Volt::mount([__DIR__.'/../resources/views/livewire']);
        $this->loadRoutesFrom(__DIR__.'/../routes/analytics.php');

        // The seam: project every delivered outbox event onto the bound sink.
        $this->app->make(Dispatcher::class)->listen(EventDelivered::class, StreamDomainEventToSink::class);

        // Console — present when a real sink is wired or analytics is explicitly on.
        Console::features()->register('analytics', fn (): bool => $this->analyticsActive());

        // Appended to the host's Overview area rather than minting an "Analytics" area
        // of its own. A single-page area for the same subject the Overview area already
        // covers gave the console two answers to "where do I see what is happening?" —
        // and the page label read "Overview" in a second place in the rail. The label
        // matches the page's own <h1>, as every nav entry must.
        //
        // "Sign-in activity" rather than "Analytics": the environment console already has
        // an Analytics page (the environment's usage counters), and two entries under one
        // label in one rail is a page nobody can find on purpose. This one charts
        // sign-ins, tokens, new users and MFA enrolments for an organization, and now
        // says so on both planes.
        //
        // Registered through ConsolePages, which serves BOTH planes by default. The old
        // call went to the organization rail's registry and nowhere else, which is how
        // this page — and every other module page — shipped on one plane.
        $this->app->make(ConsolePages::class)->add(
            area: ConsoleArea::Overview,
            route: 'sign-in-activity',
            label: 'Sign-in activity',
            feature: 'analytics',
            order: 15,
        );

        Console::dashboardCard(fn (): string => $this->loginsCard(), 4);

        if ($this->app->runningInConsole()) {
            $this->commands([AnalyticsInstallCommand::class]);
        }
    }

    /**
     * The relational store: the app's own database, one row per delivered event.
     * No connection to configure and nothing to provision beyond a migration, which
     * is what makes it the workable option where no column store exists.
     */
    private function registerDatabaseStore(): void
    {
        $this->app->bind(ReportSink::class, DatabaseReportSink::class);

        $this->app->bind(ReportReader::class, fn (Application $app): DatabaseReportReader => new DatabaseReportReader(
            $app->make(EnvironmentContext::class),
        ));
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
        return ! $this->app->make(ReportSink::class)->isInert()
            || (bool) config('id-analytics.enabled', false);
    }

    /**
     * Dashboard card: logins in the last day, for the ACTING ORGANIZATION. Empty
     * (nothing rendered) if the reader can't be read yet (no schema / no environment)
     * — never a broken dashboard.
     *
     * The organization id used to be a literal `null`, which {@see ReportReader} reads
     * as "total across the whole environment". The card renders on an organization's
     * own dashboard, so one tenant's admin was shown every OTHER tenant's sign-in
     * volume — live traffic data, on a page nobody thought of as a report. The page
     * this card links to has always passed the scope; only the card did not.
     *
     * Null is refused rather than widened, for the same reason: null means "an
     * environment administrator has not chosen an organization yet"
     * ({@see ConsoleScope::organizationId()}), and a stat with no scope is not a
     * smaller answer, it is a different one.
     */
    private function loginsCard(): string
    {
        try {
            $organizationId = $this->app->make(ConsoleScope::class)->organizationId();

            if ($organizationId === null) {
                return '';
            }

            $reader = $this->app->make(ReportReader::class);
            $until = Carbon::now();
            $logins = $reader->snapshot($organizationId, $until->copy()->subDay(), $until)['auth.login'] ?? 0;
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
