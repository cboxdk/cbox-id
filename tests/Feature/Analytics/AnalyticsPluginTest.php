<?php

declare(strict_types=1);

use Cbox\Console\Kit\ConsoleManager;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Analytics\Contracts\ReportSink;
use Cbox\Id\Analytics\Testing\FakeReportSink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('leaves the analytics feature inactive with the inert null sink and disabled config', function (): void {
    expect(Console::featureActive('analytics'))->toBeFalse();
});

it('activates the analytics feature once a real sink is bound', function (): void {
    app()->instance(ReportSink::class, new FakeReportSink);

    expect(Console::featureActive('analytics'))->toBeTrue();
});

it('activates the analytics feature when explicitly enabled without a sink', function (): void {
    config()->set('id-analytics.enabled', true);

    expect(Console::featureActive('analytics'))->toBeTrue();
});

it('adds a gated Analytics nav area with an Overview page', function (): void {
    $area = collect(Console::nav()->areas())->firstWhere('key', 'analytics');

    expect($area)->not->toBeNull();
    $pages = $area->pages();
    expect($pages[0]->route)->toBe('analytics.overview')
        ->and($pages[0]->feature)->toBe('analytics');
});

it('registers the Volt overview route', function (): void {
    expect(Route::has('analytics.overview'))->toBeTrue();
});

it('renders a dashboard analytics card', function (): void {
    $html = Console::slots()->render(ConsoleManager::DASHBOARD_CARDS);

    expect($html)->toContain('Logins');
});
