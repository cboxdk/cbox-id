<?php

declare(strict_types=1);

use Cbox\Console\Kit\ConsoleManager;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('activates the connectors feature when installed', function (): void {
    expect(Console::featureActive('connectors'))->toBeTrue();
});

it('deactivates the connectors feature when switched off in config', function (): void {
    config()->set('connectors.enabled', false);

    expect(Console::featureActive('connectors'))->toBeFalse();
});

it('adds a gated Connectors nav area with Catalog and Connections pages', function (): void {
    $area = collect(Console::nav()->areas())->firstWhere('key', 'connectors');

    expect($area)->not->toBeNull();

    $pages = $area->pages();
    expect($pages[0]->route)->toBe('connectors.catalog')
        ->and($pages[0]->feature)->toBe('connectors')
        ->and($pages[1]->route)->toBe('connectors.connections')
        ->and($pages[1]->feature)->toBe('connectors');
});

it('registers the Volt catalog and connections routes', function (): void {
    expect(Route::has('connectors.catalog'))->toBeTrue()
        ->and(Route::has('connectors.connections'))->toBeTrue();
});

it('renders a dashboard connectors card', function (): void {
    $html = Console::slots()->render(ConsoleManager::DASHBOARD_CARDS);

    expect($html)->toContain('Active connectors');
});
