<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Tests;

use Cbox\Console\Kit\ConsoleKitServiceProvider;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Compliance\ComplianceServiceProvider;
use Cbox\Id\IdServiceProvider;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
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

        // Signing keys (used to sign audit checkpoints) are environment-owned and
        // stamped with the active environment; every test runs inside one.
        $this->actingAsEnvironment('env_test');
    }

    /**
     * Record an audit entry through the real hash-chained AuditLog, so tests exercise
     * the same trail the plugin exports (system trail when $organizationId is null).
     */
    protected function recordAudit(string $action, ?string $organizationId = null, ?string $actorId = null): void
    {
        $this->app->make(AuditLog::class)->record(new AuditEvent(
            action: $action,
            actorId: $actorId,
            organizationId: $organizationId,
        ));
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        // The SSRF guard + full Cbox ID platform provide the AuditReader/AuditLog and
        // the audit tables this plugin builds on; Testbench needs them named.
        return [
            SsrfServiceProvider::class,
            IdServiceProvider::class,
            ConsoleKitServiceProvider::class,
            ComplianceServiceProvider::class,
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

        // Compliance defaults: inert (null sink, console off) unless a test opts in.
        $app['config']->set('compliance.enabled', false);
        $app['config']->set('compliance.export.sink', 'null');
    }
}
