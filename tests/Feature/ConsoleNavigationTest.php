<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

function navSetup(): string
{
    platformRootEnvironment();

    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->member, $result->environment->id);

    return $result->environment->id;
}

it('navigates the console shell without a full document load', function (): void {
    navSetup();

    $html = $this->get('/admin')->assertOk()->getContent();

    // Every sidebar click was a full document load: the whole stylesheet re-parsed and
    // Livewire and Alpine re-booted, to change the middle column. wire:navigate swaps
    // the body instead. Both tiers of the shell nav must carry it — the rail (areas)
    // and the subnav (pages within the active area).
    expect(substr_count((string) $html, 'wire:navigate'))->toBeGreaterThan(1);
});

it('bounds the conflict-rule pickers and can search them', function (): void {
    navSetup();

    foreach (range(1, 60) as $i) {
        app(Roles::class)->define(null, 'role-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
    }

    // Unbounded, this drew all 60 (and every organization) on every render.
    Volt::test('environment.sod-policies.create')
        ->assertOk()
        ->assertSee('role-001')
        ->assertSee('Showing the first 50')
        ->assertDontSee('role-060')
        ->set('roleSearch', 'role-060')
        ->assertSee('role-060')
        ->assertDontSee('role-001');
});

it('keeps a selected role visible when the search would otherwise hide it', function (): void {
    navSetup();

    $kept = app(Roles::class)->define(null, 'approve-payments');
    app(Roles::class)->define(null, 'create-purchase-order');

    // Otherwise typing a filter hides a ticked box and the rule is defined over a
    // selection the admin can no longer see.
    Volt::test('environment.sod-policies.create')
        ->set('roleIds', [$kept->id])
        ->set('roleSearch', 'purchase')
        ->assertSee('create-purchase-order')
        ->assertSee('approve-payments');
});
