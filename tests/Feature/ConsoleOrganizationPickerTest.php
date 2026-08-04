<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Livewire\Volt\Volt;

/**
 * The environment console administers many organizations; the organization console
 * administers one. Modelling that as a picker — beside the environment switcher already
 * there — is what lets one component serve both planes.
 *
 * These drove `POST /admin/acting-organization` until the picker became a search rather
 * than a `@foreach` of one form per organization. The Livewire component calls the scope
 * itself and reloads in place, so no layout rendered that route any more — and a POST is
 * not something anybody can bookmark their way back to, so there was nothing left for it
 * to serve. The route and its controller are gone; the behaviour they carried is asserted
 * here against the surface that actually exists.
 */
function anEnvironmentAdmin(): void
{
    // `/admin` exists only on a multi-tenant deployment, so an environment administrator
    // is a multi-tenant fact. {@see \App\Http\Middleware\RequireMultiTenant}.
    multiTenantDeployment();

    platformRootEnvironment();

    $provisioned = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'picker-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->member, $provisioned->environment->id);
}

it('chooses an organization in this environment', function (): void {
    anEnvironmentAdmin();
    $orgId = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co'))->id;

    Volt::test('console.organization-switcher')->call('choose', $orgId);

    expect(app(ConsoleScope::class)->organizationId())->toBe($orgId);
});

it('refuses an organization outside this environment rather than ignoring it', function (): void {
    // A silent no-op would leave an administrator looking at one organization's name
    // while acting on another's data — worse than an error, because they would not know.
    anEnvironmentAdmin();

    Volt::test('console.organization-switcher')
        ->call('choose', '01JQZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertHasErrors('search');

    expect(app(ConsoleScope::class)->organizationId())->toBeNull();
})->group('security');

it('is not reachable without an environment-admin session', function (): void {
    // The chrome is not a lesser surface than a page: it reads every organization name in
    // the environment and writes which one the console acts on. Its own boot() asks,
    // because a component a layout renders is one nobody re-checks a route for.
    platformRootEnvironment();
    multiTenantDeployment();

    Volt::test('console.organization-switcher')->assertForbidden();
})->group('security');

it('shows the picker in the console chrome', function (): void {
    anEnvironmentAdmin();
    app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co'));

    $this->get(route('environment.home'))
        ->assertOk()
        ->assertSee('Act on behalf of')
        ->assertSee('Choose organization')
        ->assertSee('Tenant Co');
});
