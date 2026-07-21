<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\PushedAuthorizationRequests;
use Cbox\Id\OAuthServer\Enums\ClientType;
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

it('routes prompt=login to add-another-account (no logout of the current one)', function () {
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
    ])->assertRedirect(route('accounts.add'));
});

it('routes prompt=select_account to the account chooser', function () {
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
    ])->assertRedirect(route('accounts'));
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
    ])->assertRedirect(
        // `iss` is REQUIRED here too (RFC 9207): a mix-up-hardened client checks it on
        // error responses as well, and this branch used to be the one path that built
        // its redirect directly and omitted it.
        'https://app.test/cb?error=interaction_required'
        .'&error_description=User+interaction+is+required+to+authorize+this+request.'
        .'&state=xyz&iss='.urlencode(app(IssuerResolver::class)->issuer())
    );
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

/**
 * RFC 6749 §4.1.2.1: once the client and its redirect_uri are verified, an error must be
 * RETURNED TO THE CLIENT, not rendered. These used to be checked before the client was
 * resolved, so the code could not redirect even when it should — an RP got an HTML page,
 * its callback never fired, and its SDK hung until timeout with no error code.
 */
it('returns authorize errors to the client as a redirect, not a page', function (): void {
    $registered = app(ClientRegistry::class)->register(new NewClient(
        'RP',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ));

    $base = [
        'client_id' => $registered->client->client_id,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'code_challenge' => 'xyz',
        'code_challenge_method' => 'S256',
        'state' => 'st-9',
    ];

    $cases = [
        // hybrid flow — we only support code
        [['response_type' => 'code id_token'], 'unsupported_response_type'],
        // PKCE omitted
        [['code_challenge' => null], 'invalid_request'],
        // plain PKCE
        [['code_challenge_method' => 'plain'], 'invalid_request'],
    ];

    foreach ($cases as [$override, $expected]) {
        $query = http_build_query(array_filter(array_merge($base, $override), fn ($v) => $v !== null));

        $location = $this->get('/oauth/authorize?'.$query)->assertRedirect()->headers->get('Location');

        expect($location)->toStartWith('https://app.test/cb?');

        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $params);

        expect($params['error'])->toBe($expected)
            ->and($params['state'])->toBe('st-9')
            ->and($params)->toHaveKey('error_description');
    }
});

/**
 * An unknown client or an unregistered redirect_uri must NOT redirect — doing so would
 * make the authorize endpoint an open redirect. Those stay rendered pages.
 */
it('renders, never redirects, when the redirect target is not trustworthy', function (): void {
    $this->get('/oauth/authorize?client_id=nope&redirect_uri=https://evil.test/cb&response_type=code&code_challenge=x&code_challenge_method=S256')
        ->assertOk()
        ->assertDontSee('evil.test/cb?error');
});

/** RFC 8252 §7.3: a native app binds an ephemeral loopback port on each run. */
it('accepts any port on a loopback redirect it registered', function (): void {
    $registered = app(ClientRegistry::class)->register(new NewClient(
        'CLI',
        ClientType::Public,
        redirectUris: ['http://127.0.0.1:8400/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ));

    $query = http_build_query([
        'client_id' => $registered->client->client_id,
        'redirect_uri' => 'http://127.0.0.1:59123/callback',  // a different port, next run
        'response_type' => 'code',
        'code_challenge' => 'xyz',
        'code_challenge_method' => 'S256',
        'prompt' => 'none',
    ]);

    // Reaching login_required (rather than the unregistered-redirect page) proves the
    // loopback URI was accepted as registered.
    $location = $this->get('/oauth/authorize?'.$query)->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('http://127.0.0.1:59123/callback?');
    expect((string) $location)->toContain('error=login_required');
});

/** A remote host still requires an exact match — the port float is loopback-only. */
it('does not float the port for a non-loopback host', function (): void {
    $registered = app(ClientRegistry::class)->register(new NewClient(
        'Web',
        ClientType::Public,
        redirectUris: ['https://app.test:443/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ));

    $query = http_build_query([
        'client_id' => $registered->client->client_id,
        'redirect_uri' => 'https://app.test:8443/cb',
        'response_type' => 'code',
        'code_challenge' => 'xyz',
        'code_challenge_method' => 'S256',
    ]);

    $this->get('/oauth/authorize?'.$query)->assertOk();  // rendered refusal, no redirect
});

/**
 * A FAPI deployment (require_par) dead-ended on its own resumed request.
 *
 * mount() consumes the single-use request_uri BEFORE the auth/prompt branches, then
 * resumeUrl() rebuilt a plain query-string URL — which require_par must refuse. So every
 * unauthenticated user, and every prompt=login/select_account, hit "this server requires
 * pushed authorization requests" after signing in. The resume now re-pushes the original
 * payload under a fresh single-use request_uri.
 */
it('resumes a pushed authorization request when PAR is required', function (): void {
    config(['cbox-id.oauth.require_par' => true]);

    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);
    $client = Client::query()->where('client_id', $clientId)->firstOrFail();

    $pushed = app(PushedAuthorizationRequests::class)->push($client, [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid',
        'state' => 'st-par',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
        'prompt' => 'login',
    ]);

    // prompt=login sends the user away to add an account; the resume URL it stores must
    // itself be a PAR request, or re-entry is refused.
    $component = Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'request_uri' => $pushed['request_uri'],
    ]);

    $intended = session('url.intended');

    expect($intended)->toContain('request_uri=')
        // NOT the consumed one — a fresh single-use handle.
        ->and($intended)->not->toContain(urlencode($pushed['request_uri']));

    $component->assertHasNoErrors();
});
