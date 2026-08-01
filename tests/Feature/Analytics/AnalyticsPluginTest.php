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

it('adds its page to the host Overview area, labelled the way the page is titled', function (): void {
    // Not its own area: Overview › Usage already answers "what is happening here", and
    // a second rail entry labelled "Overview" answered it again from somewhere else.
    expect(collect(Console::nav()->areas())->firstWhere('key', 'analytics'))->toBeNull();

    $area = collect(Console::nav()->areas())->firstWhere('key', 'overview');
    $page = collect($area->pages())->firstWhere('route', 'analytics.overview');

    expect($page)->not->toBeNull()
        ->and($page->feature)->toBe('analytics')
        ->and($page->label)->toBe('Analytics');
});

it('registers the Volt overview route', function (): void {
    expect(Route::has('analytics.overview'))->toBeTrue();
});

it('renders a dashboard analytics card', function (): void {
    $html = Console::slots()->render(ConsoleManager::DASHBOARD_CARDS);

    expect($html)->toContain('Logins');
});
