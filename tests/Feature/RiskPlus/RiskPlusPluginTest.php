<?php

declare(strict_types=1);

use Cbox\Console\Kit\ConsoleManager;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Risk\Facades\Risk;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers its premium signals into the risk pipeline (the SignalRegistry hook)', function (): void {
    $keys = collect(Risk::signals()->all())->map->key();

    expect($keys)->toContain('geo.impossible_travel', 'device.new');
});

it('activates the risk-plus console feature when installed', function (): void {
    expect(Console::featureActive('risk-plus'))->toBeTrue();
});

it('adds a gated Security nav area with a Risk events page', function (): void {
    $area = collect(Console::nav()->areas())->firstWhere('key', 'security');

    expect($area)->not->toBeNull();
    $pages = $area->pages();
    expect($pages[0]->route)->toBe('risk-plus.events')
        ->and($pages[0]->feature)->toBe('risk-plus');
});

it('renders a dashboard risk card', function (): void {
    $html = Console::slots()->render(ConsoleManager::DASHBOARD_CARDS);

    expect($html)->toContain('Risk events')->toContain('elevated');
});
