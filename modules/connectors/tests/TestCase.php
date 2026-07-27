<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Tests;

use Cbox\Console\Kit\ConsoleKitServiceProvider;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Connectors\ConnectorsServiceProvider;
use Cbox\Id\Directory\Testing\InteractsWithDirectory;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\IdServiceProvider;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;
use Cbox\Id\Organization\Testing\InteractsWithOrganizations;
use Cbox\Id\Provisioning\Testing\InteractsWithProvisioning;
use Cbox\Id\Webhooks\Testing\InteractsWithWebhooks;
use Cbox\Ssrf\SsrfServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithDirectory;
    use InteractsWithFederation;
    use InteractsWithOrganizations;
    use InteractsWithProvisioning;
    use InteractsWithTenancy;
    use InteractsWithWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        // Every connector module is environment-owned and deny-by-default: reads
        // return nothing without an active environment. Every test runs inside one.
        $this->actingAsEnvironment('env_test');
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        // The SSRF guard (URLs are validated on registration) + the full Cbox ID
        // platform provide the four connector modules this plugin reads through.
        return [
            SsrfServiceProvider::class,
            IdServiceProvider::class,
            ConsoleKitServiceProvider::class,
            ConnectorsServiceProvider::class,
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

        // The connector modules SSRF-check every target URL on registration. Tests
        // register against non-routable example hosts and never make real egress, so
        // disable enforcement for the happy paths — exactly as laravel-id's own
        // provisioning/federation tests do. The plugin under test loosens no guard;
        // it only reads existing connections.
        $app['config']->set('ssrf.enforce', false);
        $app['config']->set('cbox-id.provisioning.verify_url', false);
    }
}
