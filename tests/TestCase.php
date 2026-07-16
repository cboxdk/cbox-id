<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Tests;

use Cbox\Console\Kit\ConsoleKitServiceProvider;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Analytics\AnalyticsServiceProvider;
use Cbox\Id\IdServiceProvider;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;
use Cbox\Ssrf\SsrfServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithTenancy;

    protected function setUp(): void
    {
        parent::setUp();

        // Environment-owned models (usage counters) are deny-by-default: without an
        // active environment they return nothing. Every test runs inside one.
        $this->actingAsEnvironment('env_test');
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        // The SSRF guard + full Cbox ID platform provide the outbox (EventDelivered)
        // and usage meter this plugin builds on; Testbench needs them named.
        return [
            SsrfServiceProvider::class,
            IdServiceProvider::class,
            ConsoleKitServiceProvider::class,
            AnalyticsServiceProvider::class,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Console' => Console::class];
    }

    /**
     * A greenfield host publishes the canonical users table; load it so the
     * platform's own migrations (which run via the provider) have it available.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/cboxdk/laravel-id/database/publishable');
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cbox-id.crypto.key', base64_encode(random_bytes(32)));
        $app['config']->set('cbox-id.environments.default', 'env_test');

        // Analytics defaults: inert (null sink, Postgres reader) unless a test opts in.
        $app['config']->set('id-analytics.enabled', false);
        $app['config']->set('id-analytics.clickhouse.dsn', '');
    }
}
