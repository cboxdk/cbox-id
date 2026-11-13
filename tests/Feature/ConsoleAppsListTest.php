<?php

declare(strict_types=1);

use App\Platform\AppKind;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Inertia\Testing\AssertableInertia;

/*
| Console › Apps, cut into the groups and kinds a person looks for an app in — and an app's
| Sign-in & logout tab, which gathers the addresses that were split across two tabs.
*/

/** An environment administrator of a provisioned environment; returns a customer organization's id. */
function appsListEnvironmentAdmin(): string
{
    multiTenantDeployment();
    platformRootEnvironment();

    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'apps-list@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->owner->id, $provisioned->environment->id);

    return app(Organizations::class)->create(new NewOrganization('Tenant Co', 'apps-list-tenant'))->id;
}

function anAppOfKind(string $name, AppKind $kind, bool $firstParty = false, ?string $organizationId = null, array $redirects = []): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: $name,
        type: $kind->clientType(),
        redirectUris: $redirects !== [] ? $redirects : ($kind->needsRedirectUris() ? ['https://'.strtolower(str_replace(' ', '-', $name)).'.example/callback'] : []),
        grantTypes: $kind->grantTypes(),
        scopes: $kind->defaultScopes(),
        firstParty: $firstParty,
        organizationId: $organizationId,
    ), AuditActor::system())->client;
}

it('groups the environment\'s apps by owner, first-party first, alphabetically within', function (): void {
    $orgId = appsListEnvironmentAdmin();

    anAppOfKind('Zeta Web', AppKind::WebApp, firstParty: true);
    anAppOfKind('Alpha Web', AppKind::WebApp, firstParty: true);
    anAppOfKind('Nightly sync', AppKind::Service);
    anAppOfKind('Tenant portal', AppKind::WebApp, organizationId: $orgId);

    $this->get(route('environment.clients'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/clients/index')
            ->where('clients.0.name', 'Alpha Web')
            ->where('clients.0.group', 'first-party')
            ->where('clients.0.host', 'alpha-web.example')
            ->where('clients.1.name', 'Zeta Web')
            ->where('clients.2.name', 'Nightly sync')
            ->where('clients.2.group', 'environment')
            ->where('clients.2.kind', 'service')
            ->where('clients.2.host', null)
            ->where('clients.3.name', 'Tenant portal')
            ->where('clients.3.group', 'organizations')
            ->where('clients.3.owner', 'Tenant Co')
            ->where('groupCounts', ['first-party' => 2, 'environment' => 1, 'organizations' => 1])
            ->where('kindCounts.web', 3)
            ->where('kindCounts.service', 1));
});

it('narrows by group and by kind from the URL, and counts each under the other', function (): void {
    $orgId = appsListEnvironmentAdmin();

    anAppOfKind('Portal', AppKind::WebApp, firstParty: true);
    anAppOfKind('Mobile', AppKind::SpaOrMobile, firstParty: true);
    anAppOfKind('Deploy bot', AppKind::Service);
    anAppOfKind('Helper', AppKind::Agent);
    anAppOfKind('Terminal', AppKind::CliOrDevice);
    anAppOfKind('Tenant job', AppKind::Service, organizationId: $orgId);

    $this->get(route('environment.clients', ['type' => 'service']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kind', 'service')
            ->has('clients', 2)
            ->where('clients.0.name', 'Deploy bot')
            ->where('clients.1.name', 'Tenant job')
            ->where('groupCounts', ['first-party' => 0, 'environment' => 1, 'organizations' => 1]));

    $this->get(route('environment.clients', ['group' => 'first-party']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('group', 'first-party')
            ->has('clients', 2)
            ->where('kindCounts.web', 1)
            ->where('kindCounts.spa', 1)
            ->where('kindCounts.service', 0));

    // A value the list does not know narrows nothing rather than everything.
    $this->get(route('environment.clients', ['type' => 'bogus', 'group' => 'bogus']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kind', null)
            ->where('group', null)
            ->has('clients', 6));
});

it('files an app whose grants match no preset under Other, and only there', function (): void {
    appsListEnvironmentAdmin();

    app(ClientRegistry::class)->register(new NewClient(
        name: 'Hand-rolled',
        grantTypes: ['client_credentials', 'refresh_token'],
    ), AuditActor::system());
    anAppOfKind('Plain service', AppKind::Service);

    $this->get(route('environment.clients', ['type' => 'other']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('clients', 1)
            ->where('clients.0.name', 'Hand-rolled')
            ->where('clients.0.kind', 'other'));

    $this->get(route('environment.clients', ['type' => 'service']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('clients', 1)
            ->where('clients.0.name', 'Plain service'));
});

it('shows an organization its own apps, with the environment\'s first-party apps apart and no owner tabs', function (): void {
    [, $organization] = actingAsRole(MembershipRole::Owner);

    anAppOfKind('Own app', AppKind::WebApp, organizationId: $organization->id);
    anAppOfKind('Platform app', AppKind::WebApp, firstParty: true);
    anAppOfKind('Back office', AppKind::Service);

    $this->get(route('clients'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('clients', 1)
            ->where('clients.0.name', 'Own app')
            ->where('groupCounts', null)
            ->has('platformApps', 1)
            ->where('platformApps.0.name', 'Platform app'));
});

it('gathers an app\'s sign-in and sign-out addresses on one tab', function (): void {
    appsListEnvironmentAdmin();

    $client = anAppOfKind('Ledger', AppKind::WebApp, redirects: ['https://ledger.example/callback']);

    $this->get(route('environment.clients.sign-in', $client->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/clients/sign-in')
            ->where('browserSignIn', true)
            ->where('redirectUris', 'https://ledger.example/callback')
            ->where('logout.uri', '')
            ->where('appHeader.tabs.1.key', 'sign-in'));
});

it('saves the addresses without touching the name, and the name without touching the addresses', function (): void {
    appsListEnvironmentAdmin();
    confirmEnvironmentStepUp();

    $client = anAppOfKind('Ledger', AppKind::WebApp, redirects: ['https://ledger.example/callback']);

    $this->patch(route('environment.clients.update', $client->id), [
        'redirectUris' => "https://ledger.example/callback\nhttps://ledger.example/other",
        'postLogoutRedirectUris' => 'https://ledger.example/',
    ])->assertSessionHasNoErrors();

    $client->refresh();
    expect($client->name)->toBe('Ledger')
        ->and($client->redirect_uris)->toBe(['https://ledger.example/callback', 'https://ledger.example/other'])
        ->and($client->post_logout_redirect_uris)->toBe(['https://ledger.example/']);

    $this->patch(route('environment.clients.update', $client->id), ['name' => 'Ledger Web'])
        ->assertSessionHasNoErrors();

    $client->refresh();
    expect($client->name)->toBe('Ledger Web')
        ->and($client->redirect_uris)->toBe(['https://ledger.example/callback', 'https://ledger.example/other'])
        ->and($client->post_logout_redirect_uris)->toBe(['https://ledger.example/']);
});
