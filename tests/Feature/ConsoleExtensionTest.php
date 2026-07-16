<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pluginAdmin(): void
{
    $subject = app(Subjects::class)->create('plug@acme.test', 'Plug Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-plug'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
}

it('shows a plugin-contributed nav area in the console with no host edit', function (): void {
    pluginAdmin();

    // Exactly what a plugin's service provider would do:
    Console::nav()->area('billing', 'Billing', 'layers', 90)->page('dashboard', 'Plan');

    $this->get(route('dashboard'))->assertOk()->assertSee('Billing');
});

it('hides a feature-gated page until its feature is active', function (): void {
    pluginAdmin();
    Console::nav()->area('extras', 'Extras', 'layers', 95)->page('dashboard', 'Reports', feature: 'reports');

    // 'reports' unregistered → deny-by-default → page hidden → empty area dropped.
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Extras');

    Console::features()->register('reports', true);
    $this->get(route('dashboard'))->assertOk()->assertSee('Extras');
});

it('renders a plugin dashboard card through the slot', function (): void {
    pluginAdmin();
    Console::dashboardCard(fn (): string => '<div>PLUGIN-CARD-MARKER</div>');

    $this->get(route('dashboard'))->assertOk()->assertSee('PLUGIN-CARD-MARKER', false);
});

it('still renders the built-in nav areas from the registry', function (): void {
    pluginAdmin();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Overview')
        ->assertSee('Developers');
});
