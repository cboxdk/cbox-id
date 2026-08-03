<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;

/**
 * The environment console administers many organizations; the organization console
 * administers one. Modelling that as a picker — beside the environment switcher already
 * there — is what lets one component serve both planes.
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

    $this->post(route('environment.organization.choose'), ['organization' => $orgId])
        ->assertRedirect();

    expect(app(ConsoleScope::class)->organizationId())->toBe($orgId);
});

it('refuses an organization outside this environment rather than ignoring it', function (): void {
    // A silent no-op would leave an administrator looking at one organization's name
    // while acting on another's data — worse than an error, because they would not know.
    anEnvironmentAdmin();

    $this->post(route('environment.organization.choose'), ['organization' => '01JQZZZZZZZZZZZZZZZZZZZZZZ'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(app(ConsoleScope::class)->organizationId())->toBeNull();
})->group('security');

it('is not reachable without an environment-admin session', function (): void {
    $env = platformRootEnvironment();
    multiTenantDeployment();

    // A tenant environment, so the request is on the subject plane rather than falling back
    // to the platform root (where `/admin` is refused by the plane bulkhead and the refusal
    // would say nothing about the session gate under test).
    $tenant = Environment::query()->create([
        'name' => 'Tenant', 'slug' => 'picker-tenant', 'status' => 'active', 'is_default' => false,
    ]);
    serveOnTestHost($tenant);
    expect($env->is_default)->toBeTrue();

    // Refused by being sent AWAY — to the account host's open-environment door, not to a
    // local credential form. Account credentials are never solicited on a tenant host.
    $this->post(route('environment.organization.choose'), ['organization' => 'anything'])
        ->assertRedirect('https://cboxid.com/workspace/open/'.$tenant->id);
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
