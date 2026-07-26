<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Crypto\Support\Base64Url;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

/**
 * `max_age` and `acr_values` are the two controls OpenID Connect gives a relying party
 * to demand a FRESH or a STRONGER authentication before something sensitive — a
 * payment, an admin grant. Both were accepted and ignored: /authorize never read
 * either, so an RP calling login({maxAge: 0}) got a code minted from a day-old session
 * carrying the ORIGINAL auth_time, and one gating on aal2 got a password-only user
 * authorized and an aal1 id_token. The server said nothing either way, so the RP
 * believed the user had just re-authenticated.
 */
const STEP_UP_VERIFIER = 'a-sufficiently-long-code-verifier-1234567890';

/**
 * @return array{0: string, 1: Organization}
 */
function stepUpUser(array $amr = ['pwd'], ?int $sessionAgeSeconds = null): array
{
    $subject = app(Subjects::class)->create('member@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-stepup'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');

    $session = app(SessionManager::class)->start($subject->id, $org->id, $amr);

    if ($sessionAgeSeconds !== null) {
        $session->created_at = now()->subSeconds($sessionAgeSeconds);
        $session->save();
    }

    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    return [$subject->id, $org];
}

/** Re-authenticate: a brand-new session, exactly as add-another-account produces. */
function stepUpReauthenticate(string $subjectId, Organization $org, array $amr = ['pwd']): int
{
    $subject = app(Subjects::class)->find($subjectId);
    $session = app(SessionManager::class)->start($subjectId, $org->id, $amr);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    return (int) $session->created_at?->getTimestamp();
}

function stepUpClient(string $orgId): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        'Payments',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid', 'profile'],
        organizationId: $orgId,
    ))->client->client_id;
}

/** The RFC 6749 §4.1.2.1 error redirect this component builds, `iss` and all. */
function stepUpErrorUrl(string $error, string $description): string
{
    return 'https://app.test/cb?error='.$error
        .'&error_description='.urlencode($description)
        .'&state=st&iss='.urlencode(app(IssuerResolver::class)->issuer());
}

function stepUpParams(string $clientId, array $extra = []): array
{
    return array_merge([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid',
        'state' => 'st',
        'code_challenge' => Base64Url::encode(hash('sha256', STEP_UP_VERIFIER, true)),
        'code_challenge_method' => 'S256',
    ], $extra);
}

it('forces a re-authentication when the session is older than max_age', function () {
    [, $org] = stepUpUser(sessionAgeSeconds: 86_400); // signed in yesterday
    $clientId = stepUpClient($org->id);

    Volt::test('oauth.consent', stepUpParams($clientId, ['max_age' => '0']))
        ->assertRedirect(route('accounts.add'));
});

it('does not disturb a session that is younger than max_age', function () {
    [, $org] = stepUpUser(sessionAgeSeconds: 60);
    $clientId = stepUpClient($org->id);

    Volt::test('oauth.consent', stepUpParams($clientId, ['max_age' => '3600']))
        ->assertRenderedNotRedirected()
        ->assertSet('error', null)
        // "Undisturbed" means the consent screen — not a bounce to re-authentication.
        ->assertSee('wants to access your Cbox ID account');
});

/**
 * The point of the whole exercise: after the forced re-authentication the id_token must
 * carry the NEW auth_time. Asserting only the redirect would pass even if the resumed
 * request went on to mint a code from the original, stale session.
 */
it('issues an id_token carrying the FRESH auth_time after a max_age re-authentication', function () {
    [$subjectId, $org] = stepUpUser(sessionAgeSeconds: 86_400);
    $clientId = stepUpClient($org->id);

    $staleAuthTime = (int) app(CurrentUser::class)->session()?->created_at?->getTimestamp();

    // 1. The stale session is turned away.
    Volt::test('oauth.consent', stepUpParams($clientId, ['max_age' => '0']))
        ->assertRedirect(route('accounts.add'));

    // 2. The user signs in again; add-another-account starts a fresh session.
    $freshAuthTime = stepUpReauthenticate($subjectId, $org);
    expect($freshAuthTime)->toBeGreaterThan($staleAuthTime);

    // 3. The resumed request now passes and mints a code.
    $component = Volt::test('oauth.consent', stepUpParams($clientId, ['max_age' => '0', 'reauthed' => '1']))
        ->assertRenderedNotRedirected()
        ->assertSet('error', null)
        ->assertSee('wants to access your Cbox ID account');

    $component->call('approve');

    parse_str((string) parse_url((string) $component->effects['redirect'], PHP_URL_QUERY), $query);
    expect($query['code'] ?? null)->toBeString();

    // 4. Exchange it and read the id_token the relying party actually receives.
    $idToken = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'code' => $query['code'],
        'redirect_uri' => 'https://app.test/cb',
        'code_verifier' => STEP_UP_VERIFIER,
    ])->assertOk()->json('id_token');

    $claims = app(TokenSigner::class)->verify($idToken, [SigningAlg::RS256]);

    expect($claims->get('auth_time'))->toBe($freshAuthTime)
        ->and($claims->get('auth_time'))->not->toBe($staleAuthTime);
});

/**
 * The regression this pins: `max_age=0` was UNSATISFIABLE.
 *
 * The old comparison was `authTime < time() - maxAge`, which at maxAge=0 reads
 * "authenticated strictly before this instant" — true of every session that exists,
 * including one created a second ago. So leg 1 sent the user to re-authenticate, the
 * user did, and leg 2 returned `login_required` anyway. The existing coverage passed
 * only because the re-auth helper and the next Volt::test ran inside the same wall-clock
 * second, so this test moves the clock and the previous one structurally could not fail.
 */
it('accepts a session created seconds ago when max_age is 0', function () {
    [$subjectId, $org] = stepUpUser(sessionAgeSeconds: 86_400);
    $clientId = stepUpClient($org->id);

    Volt::test('oauth.consent', stepUpParams($clientId, ['max_age' => '0']))
        ->assertRedirect(route('accounts.add'));

    stepUpReauthenticate($subjectId, $org);

    // The redirect back from the sign-in screen is never instantaneous.
    $this->travelTo(now()->addSeconds(5));

    // assertRenderedNotRedirected(), never assertNoRedirect(): a mount-time redirect is
    // an HTTP response rather than a Livewire effect here, and assertNoRedirect() only
    // inspects the effect — it is silent about exactly the failure this test exists to
    // catch. The minted code in the callback query is the second, positive proof that
    // the request survived both gates.
    $component = Volt::test('oauth.consent', stepUpParams($clientId, ['max_age' => '0', 'reauthed' => '1']))
        ->assertRenderedNotRedirected()
        ->assertSet('error', null);

    $component->call('approve')->assertSet('error', null);

    parse_str((string) parse_url((string) $component->effects['redirect'], PHP_URL_QUERY), $query);

    expect($query['code'] ?? null)->toBeString()
        ->and($query['error'] ?? null)->toBeNull();
});

/**
 * ...and the allowance that makes the round trip survivable is BOUNDED: it covers one
 * redirect, not a session left open over lunch.
 */
it('still refuses a session older than the max_age skew allowance', function () {
    [$subjectId, $org] = stepUpUser(sessionAgeSeconds: 86_400);
    $clientId = stepUpClient($org->id);

    stepUpReauthenticate($subjectId, $org);

    $this->travelTo(now()->addHour());

    Volt::test('oauth.consent', stepUpParams($clientId, ['max_age' => '0', 'reauthed' => '1']))
        ->assertRedirect(stepUpErrorUrl('login_required', 'The existing authentication is older than the requested max_age.'));
});

it('returns login_required rather than sign-in UI when max_age is unmet under prompt=none', function () {
    [, $org] = stepUpUser(sessionAgeSeconds: 86_400);
    $clientId = stepUpClient($org->id);

    Volt::test('oauth.consent', stepUpParams($clientId, ['max_age' => '0', 'prompt' => 'none']))
        ->assertRedirect(stepUpErrorUrl('login_required', 'The existing authentication is older than the requested max_age.'));
});

/**
 * Trusting `reauthed=1` would make the whole gate bypassable with a query parameter.
 * Coming back still unsatisfied means the requirement cannot be met here, and the
 * honest answer is an error — never a token quietly asserting less than was demanded.
 */
it('refuses rather than issuing a stale code when the resumed request is still too old', function () {
    [, $org] = stepUpUser(sessionAgeSeconds: 86_400);
    $clientId = stepUpClient($org->id);

    Volt::test('oauth.consent', stepUpParams($clientId, ['max_age' => '0', 'reauthed' => '1']))
        ->assertRedirect(stepUpErrorUrl('login_required', 'The existing authentication is older than the requested max_age.'));
});

it('steps up when acr_values asks for aal2 and the session used one factor', function () {
    [, $org] = stepUpUser(['pwd']);
    $clientId = stepUpClient($org->id);

    Volt::test('oauth.consent', stepUpParams($clientId, ['acr_values' => 'urn:cbox-id:aal2']))
        ->assertRedirect(route('accounts.add'));
});

it('authorizes without interruption when the session already meets aal2', function () {
    [, $org] = stepUpUser(['pwd', 'mfa']);
    $clientId = stepUpClient($org->id);

    Volt::test('oauth.consent', stepUpParams($clientId, ['acr_values' => 'urn:cbox-id:aal2']))
        ->assertRenderedNotRedirected()
        ->assertSet('error', null)
        // "Without interruption" = the consent screen, not a bounce to a second factor.
        ->assertSee('wants to access your Cbox ID account');
});

it('never issues an aal1 token to a client that demanded aal2', function () {
    [, $org] = stepUpUser(['pwd']);
    $clientId = stepUpClient($org->id);

    // Re-authenticated and STILL single-factor: refuse, do not downgrade silently.
    // RFC 9470 §3 — "authenticated, but not strongly enough".
    Volt::test('oauth.consent', stepUpParams($clientId, [
        'acr_values' => 'urn:cbox-id:aal2',
        'reauthed' => '1',
    ]))->assertRedirect(stepUpErrorUrl(
        // RFC 9470 §5 / OIDCUAR — the AUTHORIZATION-request error. RFC 9470 §3's
        // `insufficient_user_authentication` is the protected-resource challenge and
        // an RP following the OIDF step-up pattern does not branch on it here.
        'unmet_authentication_requirements',
        'The requested authentication context (urn:cbox-id:aal2) was not met by this session.',
    ));
});

it('ignores an acr_values naming only classes this server does not assert', function () {
    [, $org] = stepUpUser(['pwd']);
    $clientId = stepUpClient($org->id);

    // acr_values is an ordered list of ACCEPTABLE classes, so a value another IdP
    // understands must not turn into a refusal here.
    Volt::test('oauth.consent', stepUpParams($clientId, ['acr_values' => 'urn:example:loa3']))
        ->assertRenderedNotRedirected()
        ->assertSet('error', null)
        // Ignored, not refused: the request proceeds to the ordinary consent screen.
        ->assertSee('wants to access your Cbox ID account');
});

it('carries the step-up requirement across the re-authentication round trip', function () {
    [, $org] = stepUpUser(sessionAgeSeconds: 86_400);
    $clientId = stepUpClient($org->id);

    Volt::test('oauth.consent', stepUpParams($clientId, ['max_age' => '30', 'acr_values' => 'urn:cbox-id:aal2']))
        ->assertRedirect(route('accounts.add'));

    // A requirement dropped on the way through the login screen is no requirement at
    // all, so the resume URL must re-state both parameters.
    $resume = session()->get('url.intended');

    expect($resume)->toBeString()
        ->and($resume)->toContain('max_age=30')
        ->and(urldecode((string) $resume))->toContain('acr_values=urn:cbox-id:aal2')
        ->and($resume)->toContain('reauthed=1');
});

it('re-asserts the requirement at approve() time, not only at mount()', function () {
    [$subjectId, $org] = stepUpUser(['pwd', 'mfa']);
    $clientId = stepUpClient($org->id);

    // mount() is satisfied by the aal2 session, so the consent screen renders.
    $component = Volt::test('oauth.consent', stepUpParams($clientId, ['acr_values' => 'urn:cbox-id:aal2']))
        ->assertRenderedNotRedirected()
        ->assertSet('error', null)
        ->assertSee('wants to access your Cbox ID account');

    // The active session is swapped for a weaker one between rendering and approving.
    stepUpReauthenticate($subjectId, $org, ['pwd']);

    $component->call('approve')->assertSet('error', 'This application requires a more recent or stronger sign-in. Please start again.');
});
