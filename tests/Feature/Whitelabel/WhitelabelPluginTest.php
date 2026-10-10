<?php

declare(strict_types=1);

use App\Platform\Console\DashboardCards;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('activates the whitelabel console feature when installed', function (): void {
    expect(Console::featureActive('whitelabel'))->toBeTrue();
});

/**
 * ONE BRANDING PAGE, AND IT IS THE HOST'S. The module's own page and the host's Appearance
 * page both set the logo and the colours, and the rail read "Branding › Branding"; the
 * module now registers no page and edits its half through its actions on the host's page.
 */
it('adds no console page of its own — its half is on the host\'s Branding page', function (): void {
    $area = collect(Console::nav()->areas())->firstWhere('key', 'settings');

    expect($area)->not->toBeNull()
        ->and(collect($area->pages())->pluck('route')->all())->toContain('branding')
        ->not->toContain('whitelabel.branding')
        ->and(Route::has('whitelabel.branding'))->toBeFalse();
});

it('edits its half through its own actions on the host\'s page', function (): void {
    expect(Route::has('branding.profile.update'))->toBeTrue()
        ->and(Route::has('environment.branding.profile.update'))->toBeTrue();
});

it('contributes a branding dashboard card', function (): void {
    // THE CARD AS DATA, not as a rendered string.
    $card = collect(app(DashboardCards::class)->resolve())->firstWhere('key', 'whitelabel.branding');

    expect($card)->not->toBeNull()
        ->and($card->label)->toBe('Branding');
});
