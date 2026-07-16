<?php

declare(strict_types=1);

use Cbox\Console\Kit\ConsoleManager;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('activates the whitelabel console feature when installed', function (): void {
    expect(Console::featureActive('whitelabel'))->toBeTrue();
});

it('adds a gated Settings nav area with a Branding page', function (): void {
    $area = collect(Console::nav()->areas())->firstWhere('key', 'settings');

    expect($area)->not->toBeNull();
    expect($area->label)->toBe('Settings');

    $pages = $area->pages();
    expect($pages[0]->route)->toBe('whitelabel.branding')
        ->and($pages[0]->feature)->toBe('whitelabel');
});

it('registers the gated branding route', function (): void {
    expect(Route::has('whitelabel.branding'))->toBeTrue();
});

it('contributes a branding dashboard card', function (): void {
    $html = Console::slots()->render(ConsoleManager::DASHBOARD_CARDS);

    expect($html)->toContain('Branding');
});
