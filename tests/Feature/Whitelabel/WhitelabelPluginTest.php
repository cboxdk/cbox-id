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

it('adds a gated Branding page to the Settings nav area', function (): void {
    $area = collect(Console::nav()->areas())->firstWhere('key', 'settings');

    expect($area)->not->toBeNull();
    expect($area->label)->toBe('Settings');

    // Looked up by route, not by position. In the module's own package suite it was
    // the only contributor to this area, so `pages()[0]` was it; in the app the host's
    // own Settings pages register first and the module appends to them. Position is
    // not what this asserts — that the module contributes a whitelabel-gated Branding
    // page is.
    $page = collect($area->pages())->firstWhere('route', 'whitelabel.branding');

    expect($page)->not->toBeNull()
        ->and($page->feature)->toBe('whitelabel');
});

it('registers the gated branding route', function (): void {
    expect(Route::has('whitelabel.branding'))->toBeTrue();
});

it('contributes a branding dashboard card', function (): void {
    $html = Console::slots()->render(ConsoleManager::DASHBOARD_CARDS);

    expect($html)->toContain('Branding');
});
