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

it('adds its Risk events page to the host Logs area rather than a "Security" one', function (): void {
    // A one-page "Security" area collided with My account › Security in the rail —
    // the same word for two unrelated destinations.
    expect(collect(Console::nav()->areas())->firstWhere('key', 'security'))->toBeNull();

    $area = collect(Console::nav()->areas())->firstWhere('key', 'audit');
    $page = collect($area->pages())->firstWhere('route', 'risk-plus.events');

    expect($page)->not->toBeNull()
        ->and($page->feature)->toBe('risk-plus')
        ->and($page->label)->toBe('Risk events');
});

it('renders a dashboard risk card', function (): void {
    $html = Console::slots()->render(ConsoleManager::DASHBOARD_CARDS);

    expect($html)->toContain('Risk events')->toContain('elevated');
});
