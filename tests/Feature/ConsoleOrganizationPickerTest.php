<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
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
    $this->post(route('environment.organization.choose'), ['organization' => 'anything'])
        ->assertRedirect(route('admin.login'));
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
