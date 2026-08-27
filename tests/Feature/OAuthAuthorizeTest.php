<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\PushedAuthorizationRequests;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\AuthorizationCode;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * Sign somebody in the way a real request arrives signed in.
 *
 * BOTH HALVES. `CurrentUser::set()` is what the auth middleware populates and what the
 * controller reads; the session key is what makes the middleware populate it on the NEXT
 * request. This used to set only the first, which was enough for a component driven
 * in-process and nothing at all to an HTTP request — /authorize would have answered every
 * one of these as an unauthenticated caller and redirected to sign-in.
 *
 * @return array{0: string, 1: Organization}
 */
function actingAsConsentUser(): array
{
    $subject = app(Subjects::class)->create('member@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-consent'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

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

    authorizeRequest([
        'client_id' => 'does-not-exist',
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
    ])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('error', fn (?string $e): bool => $e !== null));
});

it('rejects a redirect_uri not registered to the client', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => 'https://evil.test/cb',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
    ])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('error', fn (?string $e): bool => $e !== null));
});

it('routes prompt=login to add-another-account (no logout of the current one)', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
        'prompt' => 'login',
    ])->assertRedirect(route('accounts.add'));
});

it('routes prompt=select_account to the account chooser', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
        'prompt' => 'select_account',
    ])->assertRedirect(route('accounts'));
});

it('does not re-prompt once re-authenticated (loop guard)', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
        'prompt' => 'login',
        'reauthed' => '1',
    ])
        ->assertOk()
        // The consent screen itself is what "no re-prompt" looks like: it carries a client
        // to authorize and an approve control, and no error.
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('error')
            ->has('client')
            ->has('approveHref'));
});

it('returns interaction_required on prompt=none when consent would be shown', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id); // third-party by default → needs consent

    authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
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

/**
 * THE VALIDATED REQUEST IS NOT SOMETHING THE BROWSER HOLDS.
 *
 * This used to assert that Livewire refused to mutate a `#[Locked]` property — the lock
 * that stood between a re-hydrated public property and an attacker swapping in an
 * unregistered `redirect_uri` after validation, which is an open redirect and a code
 * exfiltration.
 *
 * There are no properties now. The validated request lives in the session and the page
 * carries an opaque id, so the same attack has nothing to aim at: the strongest thing the
 * browser can do is post a DIFFERENT id, and the only ids that resolve are ones this same
 * session pushed. Asserted by trying it — a foreign id, and an id from another session.
 */
it('never lets the browser name the request it is approving', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    $props = consentScreen([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
    ]);

    // Nothing on the page names the client, the redirect target or the challenge in a form
    // anything could post back: the id is the whole of what the browser is given.
    expect($props)->not->toHaveKey('redirectUri')
        ->and($props)->not->toHaveKey('codeChallenge')
        ->and($props['approveHref'])->toBeString();

    // An id this session never pushed resolves to nothing, and mints nothing.
    $refused = inertiaRequest(fn (): TestResponse => test()->from(route('oauth.authorize'))
        ->post(route('oauth.authorize.approve', '01ffffffffffffffffffffffff')))
        ->assertOk();

    expect(consentRefusal($refused))->toBeString();

    expect(AuthorizationCode::query()->count())->toBe(0);
})->group('security');

it('refuses to mint a code at approval if the client/redirect is no longer valid', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    $props = consentScreen([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
    ]);

    // Client deregistered between the render and the approval — issuance re-asserts the
    // invariant instead of trusting the render, so no code is minted.
    Client::query()->where('client_id', $clientId)->delete();

    expect(consentRefusal(answerConsent($props)->assertOk()))->toBeString()
        ->and(AuthorizationCode::query()->count())->toBe(0);
});

/**
 * A SUSPENDED ORGANIZATION IS REFUSED AT THE DOOR, before /authorize runs at all.
 *
 * This used to drive the component and assert that its own `OrganizationAccess` check
 * answered at approval — which it had to, because a Volt action never met route
 * middleware. Over a real request it never got that far even then: `Authenticate` asks
 * {@see OrganizationAccess} of every authenticated request, /authorize included, so the
 * component's copy was unreachable the whole time and the test could not see it.
 *
 * So this asks the door that answers, on the read AND on both writes — put one of them
 * outside the stack and this fails, which is the only way the property can be lost.
 */
it('refuses a suspended organization the authorize endpoint', function () {
    $subject = app(Subjects::class)->create('susp-consent@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-susp-consent'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $org->update(['status' => OrganizationStatus::Suspended]);
    $org->refresh();
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    $clientId = registerConsentClient($org->id);

    authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
    ])->assertForbidden();

    test()->post(route('oauth.authorize.approve', '01ffffffffffffffffffffffff'))->assertForbidden();
    test()->post(route('oauth.authorize.deny', '01ffffffffffffffffffffffff'))->assertForbidden();

    expect(AuthorizationCode::query()->count())->toBe(0);
})->group('security');

it('issues a code and redirects on approve for a valid request', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    $props = consentScreen([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
    ]);

    // The screen names the app asking, which is the whole reason it exists.
    expect($props['client']['name'])->toBe('App');

    $redirect = leftFor(answerConsent($props));

    expect($redirect)->toStartWith('https://app.test/cb?')
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
        'scope' => 'openid',
        'state' => 'st',
        'code_challenge' => pkceChallenge(),
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
    ];
}

it('skips consent for a first-party client owned by the user\'s own org', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerFirstPartyClient($org->id);

    // Nothing is answered — the request itself issues and redirects for a first-party
    // client owned by this person's own organization.
    authorizeRequest(fpAuthorizeParams($clientId))->assertRedirect();
});

it('skips consent for a platform-owned first-party client', function () {
    actingAsConsentUser();
    $clientId = registerFirstPartyClient(null); // platform-owned (organization_id null)

    authorizeRequest(fpAuthorizeParams($clientId))->assertRedirect();
});

it('does NOT skip consent for a first-party client owned by a DIFFERENT org', function () {
    actingAsConsentUser(); // member of "acme-consent"
    $otherOrg = app(Organizations::class)->create(new NewOrganization('Other', 'other-org'));
    $clientId = registerFirstPartyClient($otherOrg->id); // owned by another tenant

    // Cross-org: never auto-skip — the consent screen must be shown, no code minted.
    authorizeRequest(fpAuthorizeParams($clientId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('error')
            ->has('client')
            ->has('approveHref'));
});

it('does NOT skip consent for a non-first-party client', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id); // first_party = false

    authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid',
        'state' => 'st',
        'code_challenge' => pkceChallenge(),
    ])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('error')
            ->has('client')
            ->has('approveHref'));
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
        'code_challenge' => pkceChallenge(),
        'response_type' => 'code',
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
        'code_challenge' => pkceChallenge(),
        'response_type' => 'code',
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
        'code_challenge' => pkceChallenge(),
        'response_type' => 'code',
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
        'scope' => 'openid',
        'state' => 'st-par',
        'code_challenge' => pkceChallenge(),
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
        'prompt' => 'login',
    ]);

    // prompt=login sends the person away to add an account; the resume URL it stores must
    // itself be a PAR request, or re-entry is refused.
    test()->get(route('oauth.authorize', [
        'client_id' => $clientId,
        'request_uri' => $pushed['request_uri'],
    ]))->assertRedirect(route('accounts.add'));

    $intended = session('url.intended');

    expect($intended)->toContain('request_uri=')
        // NOT the consumed one — a fresh single-use handle.
        ->and($intended)->not->toContain(urlencode($pushed['request_uri']));
});

/**
 * RFC 9207 §2: the AS MUST include `iss` in authorization responses INCLUDING error
 * responses, and a client MUST reject a response without it. deny() was the last path
 * that omitted it — so an RP using oauth4webapi threw on user-cancel instead of showing
 * "you declined".
 */
it('returns the RFC 9207 issuer when the user denies consent', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    $target = leftFor(answerConsent(consentScreen([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
    ]), 'deny'));

    expect($target)->toBe(
        'https://app.test/cb?error=access_denied'
        .'&iss='.urlencode(app(IssuerResolver::class)->issuer())
        .'&state=xyz'
    );
});

/**
 * The resource a client asks for at /authorize is what the token is minted for.
 *
 * Captured here and bound to the code, so the token endpoint has something to compare a
 * redemption against. Without this capture the framework's binding is inert: every code
 * carries null, and a client is free to name any audience at redemption again.
 */
it('binds the requested resource to the issued authorization code', function (): void {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    $props = consentScreen([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid',
        'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        'resource' => 'https://mcp.acme.example',
    ]);

    answerConsent($props);

    // ASKED OF THE CODE, not of a property. The resource never reaches the browser at all
    // now — it is held with the rest of the validated request — so the only place it can be
    // observed is the grant it was bound to, which is also the only place that matters.
    expect(AuthorizationCode::query()->latest('created_at')->first()?->resource)
        ->toBe('https://mcp.acme.example', 'the authorization was not bound to the resource it asked for');
});

/*
 * Private-use scheme redirect URIs (RFC 8252 §7.1) — the whole native-app category.
 *
 * `com.example.app:/oauth/callback` has NO authority. Two independent defects stacked on
 * it, and each hid the other: buildRedirect() assumed an authority and emitted three
 * slashes, and on a non-Livewire request Laravel's UrlGenerator::to() rejected the result
 * as "not a URL" and prepended the app root. The authorization code ended up in a URL on
 * our own host that matched no route, so the client saw a 404 and never got its code.
 */
it('preserves a private-use scheme redirect exactly as registered', function (): void {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id, ['com.cboxid.authenticator:/oauth/callback']);

    $target = leftFor(answerConsent(consentScreen([
        'client_id' => $clientId,
        'redirect_uri' => 'com.cboxid.authenticator:/oauth/callback',
        'scope' => 'openid',
        'state' => 'xyz',
        'code_challenge' => str_repeat('a', 43),
    ])));

    // One slash, as registered. Three means an authority we invented — and the client's
    // URL handler is listening for what it registered, not for what we rebuilt.
    expect($target)->toStartWith('com.cboxid.authenticator:/oauth/callback?')
        ->and($target)->not->toContain(':///')
        // And it must not have been rewritten onto our own host.
        ->and($target)->not->toContain('cbox-id.test')
        ->and($target)->toContain('code=')
        ->and($target)->toContain('state=xyz');
})->group('security');

it('still builds an ordinary https redirect correctly', function (): void {
    // The other form has to keep working — the fix touches the one code path both use.
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id, ['https://app.test/cb']);

    $target = leftFor(answerConsent(consentScreen([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid',
        'state' => 'xyz',
        'code_challenge' => str_repeat('a', 43),
    ])));

    expect($target)->toStartWith('https://app.test/cb?');
});

it('keeps a query the client registered rather than dropping it', function (): void {
    // A registered URI may legitimately carry its own parameters. Rebuilding from
    // parse_url() preserved them by luck; appending preserves them by construction.
    [, $org] = actingAsConsentUser();
    $uri = 'https://app.test/cb?tenant=acme';
    $clientId = registerConsentClient($org->id, [$uri]);

    $target = leftFor(answerConsent(consentScreen([
        'client_id' => $clientId,
        'redirect_uri' => $uri,
        'scope' => 'openid',
        'code_challenge' => str_repeat('a', 43),
    ])));

    expect($target)->toContain('tenant=acme')
        ->and($target)->toContain('code=');
});

it('carries a private-use scheme through a denial too', function (): void {
    // Deny goes through the same builder. A native app that gets a mangled error
    // redirect hangs on its callback listener exactly as it would on a mangled success.
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id, ['com.cboxid.authenticator:/oauth/callback']);

    $target = leftFor(answerConsent(consentScreen([
        'client_id' => $clientId,
        'redirect_uri' => 'com.cboxid.authenticator:/oauth/callback',
        'scope' => 'openid',
        'state' => 'xyz',
        'code_challenge' => str_repeat('a', 43),
    ]), 'deny'));

    expect($target)->toStartWith('com.cboxid.authenticator:/oauth/callback?')
        ->and($target)->toContain('error=access_denied');
})->group('security');

it('does not rewrite a private-use redirect onto our own host on the non-Livewire path', function (): void {
    /*
     * THE path that produced the 404, and the one none of the tests above reach.
     *
     * Volt::test() is a Livewire request, where the redirect is handed to the browser as
     * a raw effect. A native app never takes that route: its client is first-party, so
     * consent is skipped and approve() runs during MOUNT — on the initial GET, which is
     * not a Livewire request. Livewire dehydrates that through `abort(redirect($to))`,
     * and Laravel's UrlGenerator::to() treats a private-use scheme as a relative path
     * and prepends the app root:
     *
     *     https://cbox.cboxid.com/com.cboxid.authenticator:/oauth/callback?code=…
     *
     * No route matches, so the client gets a 404 and the authorization code is stranded.
     */
    $subject = app(Subjects::class)->create('native@acme.test', 'Native', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-native'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $registered = app(ClientRegistry::class)->register(new NewClient(
        'Cbox Authenticator',
        type: ClientType::Public,
        redirectUris: ['com.cboxid.authenticator:/oauth/callback'],
        grantTypes: ['authorization_code'],
        firstParty: true,
        organizationId: $org->id,
    ));

    // A real session, so the middleware populates CurrentUser on the GET below.
    test()->from(route('login'))->post(route('login.attempt'), ['email' => 'native@acme.test', 'password' => 'supersecret123']);

    $response = $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $registered->client->client_id,
        'redirect_uri' => 'com.cboxid.authenticator:/oauth/callback',
        'scope' => 'openid',
        'state' => 'xyz',
        'code_challenge' => str_repeat('a', 43),
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
    ]));

    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith('com.cboxid.authenticator:/oauth/callback?')
        ->and($location)->not->toContain(':///')
        // The failure mode, named: the app root must not appear anywhere in it.
        ->and($location)->not->toContain(url('/'))
        ->and($location)->toContain('code=')
        ->and($location)->toContain('state=xyz');
})->group('security');

/**
 * …AND SURVIVES THE SIGN-IN ROUND TRIP, which is where it stopped surviving.
 *
 * The capture above only holds for a request that arrives already authenticated. Every
 * other path — a first login, `prompt=login`, `select_account`, a `max_age` step-up —
 * bounces through the sign-in and comes back on a URL that `resumeUrl()` rebuilds from
 * the component's own state. That rebuild carried `client_id`, `redirect_uri`,
 * `response_type`, `scope`, `state`, both PKCE fields, `nonce`, `max_age` and
 * `acr_values`, and left `resource` out.
 *
 * The consequence was not "the resource is missing". It was worse: the code was minted
 * with `resource = null`, so the token endpoint's binding check — guarded on
 * `$grant->resource !== null` — no-opped, and the client's own value at REDEMPTION time
 * was taken instead. That is exactly the confused deputy the check exists to close,
 * reachable by any client whose user was not already signed in. Only the PAR path was
 * safe, because it re-pushes the payload intact rather than rebuilding it.
 *
 * Asserted on the REBUILT URL rather than on a full redirect chain: the omission was in
 * the rebuild, and a chain test would pass just as well against a resume that dropped it
 * and a login that happened to preserve the original query string.
 */
it('carries the requested resource across the sign-in round trip', function (): void {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    $component = authorizeRequest([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid',
        'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        'resource' => 'https://mcp.acme.example',
        // Forces the re-authentication branch, which is the one that rebuilds the URL.
        'prompt' => 'login',
    ]);

    // `url.intended` IS the rebuilt URL — it is what the sign-in sends the person back
    // to, so asserting it here is asserting the production observable rather than
    // reaching for a private method.
    $resume = urldecode((string) session('url.intended'));

    expect($resume)->toContain('/oauth/authorize')
        ->and($resume)->toContain('resource=https://mcp.acme.example');
})->group('security');

/**
 * A MALFORMED CHALLENGE IS AN `invalid_request` AT /authorize, NOT A 500 AT ISSUE.
 *
 * The issuer refuses anything that is not the base64url of a SHA-256 digest (RFC 7636
 * §4.2). Without the same check here, a client sending a placeholder got a consent screen,
 * pressed Allow, and hit an exception at the moment the code was minted — past the point
 * where the protocol has any way to tell them what was wrong, and with the user looking at
 * an error page instead of their app.
 *
 * Every fixture in this file used to send `code_challenge=abc`, so nothing in the suite
 * had ever exercised a conformant one.
 */
it('refuses a code_challenge that is not an S256 digest, at the authorize endpoint', function (): void {
    $registered = app(ClientRegistry::class)->register(new NewClient(
        'Malformed challenge',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ));

    $location = $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $registered->client->client_id,
        'redirect_uri' => 'https://app.test/cb',
        'code_challenge' => 'abc',
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
    ]))->assertRedirect()->headers->get('Location');

    expect((string) $location)->toStartWith('https://app.test/cb?')
        ->and((string) $location)->toContain('error=invalid_request');
});
