<?php

declare(strict_types=1);

use Cbox\Id\Analytics\AnalyticsServiceProvider;
use Cbox\Id\Analytics\Contracts\ReportReader;
use Cbox\Id\Analytics\Contracts\ReportSink;
use Cbox\Id\Analytics\Models\AnalyticsEvent;
use Cbox\Id\Analytics\Readers\DatabaseReportReader;
use Cbox\Id\Analytics\Sinks\DatabaseReportSink;
use Cbox\Id\Analytics\ValueObjects\ReportRecord;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

/*
 * The relational analytics store — what runs where there is no column store, which
 * is the hosted deployment and every self-host.
 */

function record(string $id, string $metric, string $day, string $org = 'org_a', string $env = 'env_test'): ReportRecord
{
    return new ReportRecord(
        eventId: $id,
        type: 'user.'.$metric,
        organizationId: $org,
        environmentId: $env,
        metric: $metric,
        occurredAt: Carbon::parse($day.' 12:00:00'),
        payloadDigest: str_repeat('a', 64),
    );
}

function dbSink(): DatabaseReportSink
{
    return new DatabaseReportSink;
}

function dbReader(): DatabaseReportReader
{
    return app(DatabaseReportReader::class);
}

it('binds the database sink and reader as a matched pair when the store is on', function (): void {
    config(['id-analytics.store' => 'database', 'id-analytics.clickhouse.dsn' => '']);

    // Re-register so the provider's register() runs against the new config.
    (new AnalyticsServiceProvider(app()))->register();

    expect(app(ReportSink::class))->toBeInstanceOf(DatabaseReportSink::class)
        ->and(app(ReportReader::class))->toBeInstanceOf(DatabaseReportReader::class);
});

it('writes a record and reads it back as a per-day series', function (): void {
    $this->actingAsEnvironment('env_test');

    dbSink()->write([
        record('evt_1', 'auth.login', '2026-07-01'),
        record('evt_2', 'auth.login', '2026-07-01'),
        record('evt_3', 'auth.login', '2026-07-03'),
    ]);

    $series = dbReader()->series('auth.login', null, Carbon::parse('2026-06-30'), Carbon::parse('2026-07-10'));

    // Sparse by contract: a day with no activity is absent, not zero.
    expect($series)->toBe(['2026-07-01' => 2, '2026-07-03' => 1]);
});

it('collapses a re-delivered event instead of double counting', function (): void {
    $this->actingAsEnvironment('env_test');

    // Outbox delivery is at-least-once. The same event id arriving twice must not
    // become two rows — that is what makes the numbers trustworthy at all.
    dbSink()->write([record('evt_dup', 'auth.login', '2026-07-01')]);
    dbSink()->write([record('evt_dup', 'auth.login', '2026-07-01')]);

    expect(AnalyticsEvent::query()->count())->toBe(1)
        ->and(dbReader()->series('auth.login', null, Carbon::parse('2026-06-01'), Carbon::parse('2026-08-01')))
        ->toBe(['2026-07-01' => 1]);
});

it('never reads another environment\'s events', function (): void {
    $this->actingAsEnvironment('env_a');
    dbSink()->write([record('evt_a', 'auth.login', '2026-07-01', env: 'env_a')]);
    dbSink()->write([record('evt_b', 'auth.login', '2026-07-01', env: 'env_b')]);

    expect(dbReader()->series('auth.login', null, Carbon::parse('2026-06-01'), Carbon::parse('2026-08-01')))
        ->toBe(['2026-07-01' => 1]);

    $this->actingAsEnvironment('env_b');

    expect(dbReader()->series('auth.login', null, Carbon::parse('2026-06-01'), Carbon::parse('2026-08-01')))
        ->toBe(['2026-07-01' => 1]);
});

it('returns nothing rather than everything when no environment is in context', function (): void {
    $this->actingAsEnvironment('env_a');
    dbSink()->write([record('evt_a', 'auth.login', '2026-07-01', env: 'env_a')]);

    // Deny-by-default. An unscoped read of a cross-tenant table is the failure mode
    // worth being loud about, and dropping the filter is what the ClickHouse reader
    // used to do.
    app(EnvironmentContext::class)->set(null);

    expect(dbReader()->series('auth.login', null, Carbon::parse('2026-06-01'), Carbon::parse('2026-08-01')))->toBe([])
        ->and(dbReader()->snapshot(null, Carbon::parse('2026-06-01'), Carbon::parse('2026-08-01')))->toBe([])
        ->and(dbReader()->topOrganizations('auth.login', Carbon::parse('2026-06-01'), Carbon::parse('2026-08-01')))->toBe([]);
});

it('totals every metric for the snapshot and ranks organizations', function (): void {
    $this->actingAsEnvironment('env_test');

    dbSink()->write([
        record('e1', 'auth.login', '2026-07-01', org: 'org_a'),
        record('e2', 'auth.login', '2026-07-02', org: 'org_a'),
        record('e3', 'auth.login', '2026-07-02', org: 'org_b'),
        record('e4', 'auth.mfa_enrolled', '2026-07-02', org: 'org_a'),
    ]);

    $since = Carbon::parse('2026-06-01');
    $until = Carbon::parse('2026-08-01');

    expect(dbReader()->snapshot(null, $since, $until))
        ->toBe(['auth.login' => 3, 'auth.mfa_enrolled' => 1])
        ->and(dbReader()->topOrganizations('auth.login', $since, $until))
        ->toBe(['org_a' => 2, 'org_b' => 1]);
});

it('scopes a series to one organization when asked', function (): void {
    $this->actingAsEnvironment('env_test');

    dbSink()->write([
        record('e1', 'auth.login', '2026-07-01', org: 'org_a'),
        record('e2', 'auth.login', '2026-07-01', org: 'org_b'),
    ]);

    expect(dbReader()->series('auth.login', 'org_a', Carbon::parse('2026-06-01'), Carbon::parse('2026-08-01')))
        ->toBe(['2026-07-01' => 1]);
});

it('prunes past the retention window and keeps the rest', function (): void {
    $this->actingAsEnvironment('env_test');
    config(['id-analytics.retention_days' => 30]);

    dbSink()->write([
        record('old', 'auth.login', Carbon::now()->subDays(90)->format('Y-m-d')),
        record('recent', 'auth.login', Carbon::now()->subDays(3)->format('Y-m-d')),
    ]);

    // This table is the only one that grows with traffic rather than with tenants,
    // so retention is not housekeeping — it is what keeps the store viable.
    $this->artisan('model:prune', ['--model' => [AnalyticsEvent::class]])->assertSuccessful();

    expect(AnalyticsEvent::query()->pluck('event_id')->all())->toBe(['recent']);
});

it('fails open so an analytics write never breaks event delivery', function (): void {
    $this->actingAsEnvironment('env_test');

    // The store is simply gone. Chosen over an over-long value because a length
    // overflow is only an error on the SERVER engines — sqlite ignores varchar
    // limits, so that version of this test would have passed vacuously on the
    // engine the suite runs by default, proving nothing.
    Schema::drop('id_analytics_events');

    // The caller is the outbox relay: an analytics outage must degrade to "no
    // analytics", never to a domain event that fails to deliver.
    expect(fn () => dbSink()->write([record('evt_1', 'auth.login', '2026-07-01')]))
        ->not->toThrow(Throwable::class);
});
