<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Tests;

use Cbox\Console\Kit\ConsoleKitServiceProvider;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Kernel\Tenancy\Contracts\Environment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Kernel\Tenancy\TenancyServiceProvider;
use Cbox\Id\Whitelabel\WhitelabelServiceProvider;
use Cbox\Ssrf\SsrfServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SsrfServiceProvider::class,
            TenancyServiceProvider::class,
            ConsoleKitServiceProvider::class,
            WhitelabelServiceProvider::class,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Console' => Console::class];
    }

    protected function defineEnvironment($app): void
    {
        tap($app->make(Repository::class), function (Repository $config): void {
            $config->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        // The env-scoped `environments` table the custom-domain path round-trips against.
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    /** Make the given environment key the active one for the current test. */
    protected function actingInEnvironment(Environment|string $environment): Environment
    {
        $environment = is_string($environment) ? GenericEnvironment::of($environment) : $environment;

        $this->app->make(EnvironmentContext::class)->set($environment);

        return $environment;
    }
}
