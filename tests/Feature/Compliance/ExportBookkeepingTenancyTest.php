<?php

declare(strict_types=1);

use Cbox\Id\Compliance\Models\AuditExportCursor;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Export bookkeeping was shared across environments.
 *
 * `audit_export_cursors.scope` carried a GLOBAL unique, and the system trail's scope is
 * the literal string `__system__` — so one environment's export advanced a cursor the
 * next environment then read. That export resumes from a position it never reached and
 * skips its own entries: audit rows silently absent from a customer's SIEM, which is the
 * one place absence is indistinguishable from "nothing happened".
 */
function cursorIn(string $environmentId, string $scope, int $sequence): AuditExportCursor
{
    return app(EnvironmentContext::class)->runAs(
        GenericEnvironment::of($environmentId),
        fn (): AuditExportCursor => AuditExportCursor::query()->create([
            'scope' => $scope,
            'organization_id' => null,
            'last_sequence' => $sequence,
        ]),
    );
}

it('lets two environments hold a cursor for the same scope', function (): void {
    // The exact collision: both environments export their own system trail. Under the
    // global unique, the second insert failed or overwrote the first.
    cursorIn('env_a', '__system__', 500);
    cursorIn('env_b', '__system__', 12);

    app(EnvironmentContext::class)->set(GenericEnvironment::of('env_b'));

    expect(AuditExportCursor::query()->where('scope', '__system__')->value('last_sequence'))
        ->toBe(12, 'env_b resumed from another environment\'s position');
});

it('never shows one environment another environment\'s cursors', function (): void {
    cursorIn('env_a', '__system__', 500);
    cursorIn('env_b', '__system__', 12);

    app(EnvironmentContext::class)->set(GenericEnvironment::of('env_a'));

    expect(AuditExportCursor::query()->count())->toBe(1);
});

it('scopes run history too, so a compliance surface shows one environment', function (): void {
    $make = fn (string $env, string $sink) => app(EnvironmentContext::class)->runAs(
        GenericEnvironment::of($env),
        fn (): AuditExportRun => AuditExportRun::query()->create([
            'status' => 'completed',
            'scopes_scanned' => 1,
            'entries_exported' => 10,
            'batches' => 1,
            'sink' => $sink,
        ]),
    );

    $make('env_a', 'siem-a');
    $make('env_b', 'siem-b');

    app(EnvironmentContext::class)->set(GenericEnvironment::of('env_a'));

    expect(AuditExportRun::query()->pluck('sink')->all())->toBe(['siem-a']);
});

it('denies rather than returning everything when no environment is in context', function (): void {
    cursorIn('env_a', '__system__', 500);

    app(EnvironmentContext::class)->set(null);

    expect(AuditExportCursor::query()->count())->toBe(0);
});
