<?php

declare(strict_types=1);

use Cbox\Id\Analytics\Contracts\ReportReader;
use Cbox\Id\Analytics\Readers\UsageMeterReportReader;
use Cbox\Id\Kernel\Usage\Contracts\UsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('is the Postgres/usage-meter reader by default (no ClickHouse)', function (): void {
    expect(app(ReportReader::class))->toBeInstanceOf(UsageMeterReportReader::class);
});

it('reads a per-day series and a snapshot back from the usage meter', function (): void {
    $meter = app(UsageMeter::class);

    $this->travelTo(Carbon::parse('2026-06-01 12:00:00'));
    $meter->record('auth.login', 2, 'org_test');

    $this->travelTo(Carbon::parse('2026-06-03 12:00:00'));
    $meter->record('auth.login', 3, 'org_test');
    $meter->record('auth.id_token', 4, 'org_test');

    $this->travelBack();

    $reader = app(ReportReader::class);
    $since = Carbon::parse('2026-05-25');
    $until = Carbon::parse('2026-06-10');

    expect($reader->series('auth.login', null, $since, $until))
        ->toBe(['2026-06-01' => 2, '2026-06-03' => 3]);

    expect($reader->snapshot(null, $since, $until))
        ->toBe(['auth.id_token' => 4, 'auth.login' => 5]);

    expect($reader->topOrganizations('auth.login', $since, $until, 10))
        ->toBe(['org_test' => 5]);
});
