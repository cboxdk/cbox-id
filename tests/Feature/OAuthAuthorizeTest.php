<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;

/**
 * Populate CurrentUser as the Authenticate middleware would, then drive the
 * component directly.
 *
 * @return array{0: string, 1: Organization}
 */
function actingAsConsentUser(): array
{
    $subject = app(Subjects::class)->create('member@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-consent'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');

    return [$subject->id, $org];
}

/**
 * Register an OAuth client and return its public client_id.
 *
 * @param  list<string>  $redirectUris
 */
function registerConsentClient(string $orgId, array $redirectUris = ['https://app.test/cb']): string
{
    $registered = app(ClientRegistry::class)->register(
        new NewClient('App', redirectUris: $redirectUris, organizationId: $orgId)
    );

    return $registered->client->client_id;
}

it('renders an error state for an unknown client', function () {
    [, $org] = actingAsConsentUser();
    registerConsentClient($org->id);

    Volt::test('oauth.consent', [
        'client_id' => 'does-not-exist',
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ])
        ->assertNoRedirect()
        ->assertSee('Authorization failed');
});

it('rejects a redirect_uri not registered to the client', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://evil.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ])
        ->assertNoRedirect()
        ->assertSee('Authorization failed');
});

it('forces a fresh sign-in on prompt=login so a different account can be used', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
        'prompt' => 'login',
    ])->assertRedirect(route('login'));
});

it('shows the account picker path on prompt=select_account', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ])->assertRedirect(route('login'));
});

it('does not re-prompt once re-authenticated (loop guard)', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
        'prompt' => 'login',
        'reauthed' => '1',
    ])->assertSet('error', null)->assertNoRedirect();
});

it('returns interaction_required on prompt=none when consent would be shown', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id); // third-party by default → needs consent

    Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
        'prompt' => 'none',
    ])->assertRedirect('https://app.test/cb?error=interaction_required&error_description=User+interaction+is+required+to+authorize+this+request.&state=xyz');
});

it('locks validated request parameters so the browser cannot tamper with them between requests', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    $component = Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ])->assertSet('error', null);

    // #[Locked]: a redirect_uri (or scopes) validated in mount() cannot be mutated
    // by a crafted Livewire update — the open-redirect / code-exfiltration vector.
    expect(fn () => $component->set('redirectUri', 'https://evil.test/cb'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => $component->set('scopes', ['openid', 'admin']))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('refuses to mint a code at approval if the client/redirect is no longer valid', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    $component = Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ])->assertSet('error', null);

    // Client deregistered between render and approval — approve() re-asserts the
    // invariant instead of trusting mount(), so no code is issued.
    Client::query()->where('client_id', $clientId)->delete();

    $component->call('approve');

    expect($component->effects['redirect'] ?? null)->toBeNull()
        ->and($component->get('error'))->not->toBeNull();
});

it('refuses to mint a code for a suspended organization', function () {
    $subject = app(Subjects::class)->create('susp-consent@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-susp-consent'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $org->update(['status' => OrganizationStatus::Suspended]);
    $org->refresh();
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');

    $clientId = registerConsentClient($org->id);

    $component = Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ])->assertSet('error', null);

    $component->call('approve');

    expect($component->effects['redirect'] ?? null)->toBeNull()
        ->and($component->get('error'))->not->toBeNull();
});

it('issues a code and redirects on approve for a valid request', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    $component = Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ])
        ->assertSet('error', null)
        ->assertSee('Authorize App')
        ->call('approve');

    $redirect = $component->effects['redirect'] ?? null;

    expect($redirect)->not->toBeNull()
        ->and($redirect)->toStartWith('https://app.test/cb?')
        ->and($redirect)->toContain('state=xyz')
        ->and($redirect)->toMatch('/[?&]code=/');
});

/**
 * Register a FIRST-PARTY OAuth client, optionally owned by a specific org
 * (null = platform-owned). Returns its public client_id.
 */
function registerFirstPartyClient(?string $ownerOrgId): string
{
    $registered = app(ClientRegistry::class)->register(
        new NewClient('First Party App', redirectUris: ['https://fp.test/cb'], firstParty: true, organizationId: $ownerOrgId)
    );

    return $registered->client->client_id;
}

/** @return array<string, string> */
function fpAuthorizeParams(string $clientId): array
{
    return [
        'client_id' => $clientId,
        'redirect_uri' => 'https://fp.test/cb',
        'response_type' => 'code',
        'scope' => 'openid',
        'state' => 'st',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ];
}

it('skips consent for a first-party client owned by the user\'s own org', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerFirstPartyClient($org->id);

    // No approve() call — mount() auto-issues and redirects for a first-party client.
    Volt::test('oauth.consent', fpAuthorizeParams($clientId))
        ->assertSet('error', null)
        ->assertRedirect();
});

it('skips consent for a platform-owned first-party client', function () {
    actingAsConsentUser();
    $clientId = registerFirstPartyClient(null); // platform-owned (organization_id null)

    Volt::test('oauth.consent', fpAuthorizeParams($clientId))
        ->assertSet('error', null)
        ->assertRedirect();
});

it('does NOT skip consent for a first-party client owned by a DIFFERENT org', function () {
    actingAsConsentUser(); // member of "acme-consent"
    $otherOrg = app(Organizations::class)->create(new NewOrganization('Other', 'other-org'));
    $clientId = registerFirstPartyClient($otherOrg->id); // owned by another tenant

    // Cross-org: never auto-skip — the consent screen must be shown, no code minted.
    Volt::test('oauth.consent', fpAuthorizeParams($clientId))
        ->assertSet('error', null)
        ->assertNoRedirect();
});

it('does NOT skip consent for a non-first-party client', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id); // first_party = false

    Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid',
        'state' => 'st',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ])
        ->assertSet('error', null)
        ->assertNoRedirect();
});
