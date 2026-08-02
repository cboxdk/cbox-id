<?php

declare(strict_types=1);

use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\Models\AuditExportCursor;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Cbox\Id\Compliance\Sinks\JsonlBundleExportSink;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

/**
 * The scheduled export has to work from the command line, where nothing sets a context.
 *
 * Every environment setter in this application is HTTP middleware, so `artisan` runs
 * with no environment at all. `AuditExportRun` is environment-owned: the write went out
 * with a null `environment_id` and died on the NOT NULL constraint before the command
 * reached its own error handling — every run, since the column was added. And had it
 * survived, the cursor read is scoped too, so it would have resumed from sequence zero
 * and re-shipped the whole trail on every pass.
 *
 * The suite could not see either half, because it pins an environment for every test and
 * the console page that calls the same service runs inside a request that has one. So
 * this test clears the context first, which is the actual production condition.
 */
it('exports every environment when run from the command line with no context', function (): void {
    $context = app(EnvironmentContext::class);

    $alpha = Environment::query()->create(['id' => (string) Str::ulid(), 'slug' => 'alpha', 'name' => 'Alpha']);
    $beta = Environment::query()->create(['id' => (string) Str::ulid(), 'slug' => 'beta', 'name' => 'Beta']);

    // What `artisan` actually has: nothing.
    $context->set(null);

    $this->artisan('id-compliance:export')
        ->assertSuccessful()
        ->expectsOutputToContain('[alpha]')
        ->expectsOutputToContain('[beta]');

    foreach ([$alpha, $beta] as $environment) {
        expect(AuditExportRun::query()->withoutGlobalScopes()->where('environment_id', $environment->id)->exists())
            ->toBeTrue($environment->slug.' was never exported');
    }
});

it('exports a single environment when one is named', function (): void {
    $context = app(EnvironmentContext::class);

    Environment::query()->create(['id' => (string) Str::ulid(), 'slug' => 'alpha', 'name' => 'Alpha']);
    $beta = Environment::query()->create(['id' => (string) Str::ulid(), 'slug' => 'beta', 'name' => 'Beta']);

    $context->set(null);

    $this->artisan('id-compliance:export', ['--environment' => 'beta'])
        ->assertSuccessful()
        ->expectsOutputToContain('[beta]');

    expect(AuditExportRun::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(AuditExportRun::query()->withoutGlobalScopes()->first()?->environment_id)->toBe($beta->id);
});

/**
 * Two environments must not append their system trails to the same bundle file.
 *
 * Sequence numbers are unique per (environment, scope), and the system trail's scope is
 * the literal `__system__` in every environment. Keyed on the scope alone, the JSONL
 * bundle interleaved two independent hash chains in one object — colliding sequences,
 * `prev_hash` values that do not link, and no field saying which tenant a line came
 * from. That destroys the single property the bundle exists for, that it can be
 * re-verified as a standalone cold archive; and handing it to one customer as their
 * audit evidence hands them another customer's operator-level entries.
 *
 * The database side of this collision was fixed when the cursor was keyed per
 * environment. The output path was not fixed with it — and until the export command
 * started running at all, nothing had ever produced the collision.
 */
it('writes each environment audit bundle to its own file', function (): void {
    Storage::fake('local');

    // Bound directly: the provider reads the driver at REGISTRATION, so setting config
    // inside the test is too late to change which sink is bound.
    app()->bind(AuditExportSink::class, fn (): JsonlBundleExportSink => new JsonlBundleExportSink(
        Storage::disk('local'),
        'compliance/audit',
    ));

    $context = app(EnvironmentContext::class);

    $alpha = Environment::query()->create(['id' => (string) Str::ulid(), 'slug' => 'alpha', 'name' => 'Alpha']);
    $beta = Environment::query()->create(['id' => (string) Str::ulid(), 'slug' => 'beta', 'name' => 'Beta']);

    foreach ([$alpha, $beta] as $environment) {
        $context->runAs($environment, function (): void {
            app(AuditLog::class)->record(AuditEvent::forSystem('tenant.trail'));
        });
    }

    $context->set(null);
    $this->artisan('id-compliance:export')->assertSuccessful();

    $files = Storage::disk('local')->allFiles('compliance/audit');

    expect($files)->toContain('compliance/audit/'.$alpha->id.'/__system__.jsonl')
        ->and($files)->toContain('compliance/audit/'.$beta->id.'/__system__.jsonl')
        ->and($files)->not->toContain('compliance/audit/__system__.jsonl');

    // And each line says which tenant it belongs to, so a SIEM receiving both can tell
    // them apart without relying on the path.
    $line = json_decode((string) Storage::disk('local')->get('compliance/audit/'.$alpha->id.'/__system__.jsonl'), true);

    expect($line['environment_id'] ?? null)->toBe($alpha->id);
});
