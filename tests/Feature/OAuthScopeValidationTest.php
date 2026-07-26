<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\ScopeCatalog;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

/**
 * /authorize never checked the requested scopes against the client's registered set.
 * The issuer then filtered them silently at mint time, so a client asking for more got
 * a 200, a narrower token, and nothing anywhere explaining the difference — its next
 * API call 403'd with no way to diagnose it. Refusing at /authorize puts the error in
 * front of the developer who can fix it.
 */
function scopeTestOrg(): string
{
    $subject = app(Subjects::class)->create('member@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-scopes'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    return $org->id;
}

function scopeTestClient(string $orgId, array $scopes): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        'Reports',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: $scopes,
        organizationId: $orgId,
    ))->client->client_id;
}

function scopeAuthorizeParams(string $clientId, string $scope): array
{
    return [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => $scope,
        'state' => 'st',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ];
}

it('returns invalid_scope to the client for a scope it is not registered for', function () {
    $orgId = scopeTestOrg();
    $clientId = scopeTestClient($orgId, ['openid', 'profile']);

    Volt::test('oauth.consent', scopeAuthorizeParams($clientId, 'openid profile organizations offline_access'))
        ->assertRedirect(
            'https://app.test/cb?error=invalid_scope'
            .'&error_description='.urlencode('This application is not registered for the requested scope(s): organizations offline_access.')
            .'&state=st&iss='.urlencode(app(IssuerResolver::class)->issuer())
        );
});

it('authorizes normally when every requested scope is registered', function () {
    $orgId = scopeTestOrg();
    $clientId = scopeTestClient($orgId, ['openid', 'profile', 'email']);

    Volt::test('oauth.consent', scopeAuthorizeParams($clientId, 'openid profile'))
        ->assertRenderedNotRedirected()
        ->assertSet('error', null)
        ->assertSet('scopes', ['openid', 'profile'])
        ->assertSee('wants to access your Cbox ID account');
});

it('does not constrain a client that registered no scopes at all', function () {
    $orgId = scopeTestOrg();
    $clientId = scopeTestClient($orgId, []);

    // Nothing was declared, so there is nothing to check against — and the issuer
    // already grants such a client nothing.
    Volt::test('oauth.consent', scopeAuthorizeParams($clientId, 'openid email'))
        ->assertRenderedNotRedirected()
        ->assertSet('error', null)
        ->assertSet('scopes', ['openid', 'email'])
        ->assertSee('wants to access your Cbox ID account');
});

/**
 * `organizations` is advertised in discovery and emitted as a claim, but was missing
 * from the console picker AND the dynamic-registration allow-list — reachable only
 * through an undiscoverable free-text box, and never at all for a self-registering
 * client.
 */
it('offers the organizations scope in the console picker', function () {
    expect(app(ScopeCatalog::class)->keys())->toContain('organizations');
});

/**
 * ...and once it is pickable, a client registered for it must get through /authorize
 * with it — the scope gate above is the other half of making it genuinely reachable.
 */
it('authorizes a client registered for the organizations scope', function () {
    $orgId = scopeTestOrg();
    $clientId = scopeTestClient($orgId, ['openid', 'organizations']);

    Volt::test('oauth.consent', scopeAuthorizeParams($clientId, 'openid organizations'))
        ->assertRenderedNotRedirected()
        ->assertSet('error', null)
        ->assertSet('scopes', ['openid', 'organizations'])
        ->assertSee('wants to access your Cbox ID account');
});
