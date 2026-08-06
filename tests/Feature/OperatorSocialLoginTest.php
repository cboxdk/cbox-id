<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Platform\Social\OperatorProviders;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Ssrf\Contracts\UrlGuard;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // These drive the operator sign-in surface, which — like every page — exists only on
    // a deployment somebody installed. Unclaimed, the first-run bulkhead points at the
    // setup screen and the assertions below would be about that instead.
    installedDeployment();
});

/**
 * Operator-level social sign-in, after it stopped running on a third-party driver and
 * started running on the platform's own federation clients.
 *
 * The protocol itself belongs to the framework and is proven there. What is proven here
 * is the inch this app owns: that the operator's deployment configuration reaches those
 * clients correctly, that a half-configured provider is never offered, and that the
 * guards the old driver used to keep out of sight — state, nonce, and the SSRF gate on
 * every outbound call — are present and actually refuse.
 */

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

/** Google's real discovery document, as fetched from accounts.google.com. */
function googleDiscovery(): array
{
    return [
        'issuer' => 'https://accounts.google.com',
        'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_endpoint' => 'https://oauth2.googleapis.com/token',
        'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
    ];
}

/**
 * A real RSA keypair, a real RS256-signed id_token, and the JWKS that verifies it.
 *
 * Real crypto rather than a validator double: the point of routing these providers
 * through the platform's own client is that the signature is checked and the algorithm
 * is pinned, and a mocked validator would prove neither.
 */
function googleIdToken(array $claims = []): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $details = openssl_pkey_get_details($key);

    $base64Url = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    $jwks = ['keys' => [[
        'kty' => 'RSA',
        'kid' => 'test-key',
        'use' => 'sig',
        'alg' => 'RS256',
        'n' => $base64Url($details['rsa']['n']),
        'e' => $base64Url($details['rsa']['e']),
    ]]];

    $idToken = JWT::encode(array_merge([
        'iss' => 'https://accounts.google.com',
        'aud' => 'google-client-id',
        'sub' => 'google|12345',
        'email' => 'newcomer@example.test',
        'name' => 'New Comer',
        'iat' => time(),
        'exp' => time() + 300,
    ], $claims), $pem, 'RS256', 'test-key');

    return ['id_token' => $idToken, 'jwks' => $jwks];
}

/**
 * Configure the operator's Google credentials and fake ONLY the discovery document —
 * everything the authorization request needs, and nothing that would pin a token before
 * the application has issued the nonce it must carry.
 */
function fakeGoogleDiscovery(): void
{
    config([
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        // The SSRF gate is exercised by its own test below; elsewhere it would mean a
        // real DNS lookup for accounts.google.com in every test.
        'cbox-id.federation.verify_url' => false,
    ]);

    Http::fake([
        'accounts.google.com/.well-known/openid-configuration' => Http::response(googleDiscovery()),
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);
}

/**
 * Start the flow for real, so the state and nonce under test are the ones the
 * application actually issued rather than ones the test invented.
 */
function startGoogleSignIn(): array
{
    test()->get('/auth/google/redirect')->assertRedirect();

    foreach (session()->all() as $key => $value) {
        if (str_starts_with((string) $key, 'social:google:') && is_array($value)) {
            return $value;
        }
    }

    return [];
}

/**
 * Begin a sign-in, then arm the provider with an id_token carrying the nonce the
 * application actually issued — the order a real provider works in, and the only way the
 * nonce check can be satisfied honestly.
 *
 * The token stub is registered only AFTER the redirect, because Http::fake() MERGES its
 * stubs and the first match wins: a token stubbed up front could never be replaced.
 *
 * `$jwks` lets a caller serve keys that do NOT verify the signature.
 *
 * @return array{state: string, nonce: string}
 */
function googleSignInInProgress(array $claims = [], ?array $jwks = null): array
{
    fakeGoogleDiscovery();

    $stash = startGoogleSignIn();

    $signed = googleIdToken(array_merge(['nonce' => $stash['nonce']], $claims));

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['id_token' => $signed['id_token']]),
        'www.googleapis.com/oauth2/v3/certs' => Http::response($jwks ?? $signed['jwks']),
    ]);

    return $stash;
}

/*
|--------------------------------------------------------------------------
| Which providers are offered
|--------------------------------------------------------------------------
*/

it('offers a provider only once its credentials are complete', function () {
    $providers = app(OperatorProviders::class);

    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
    expect($providers->find('google'))->toBeNull();

    // A client id with no secret cannot complete a token exchange. Offering it would
    // put the failure in front of the person clicking, not the operator who misconfigured.
    config(['services.google.client_id' => 'id', 'services.google.client_secret' => null]);
    expect($providers->find('google'))->toBeNull();

    config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    expect($providers->find('google')?->key())->toBe('google');
});

it('refuses to offer Entra until a directory is named', function () {
    $providers = app(OperatorProviders::class);

    config([
        'services.microsoft.client_id' => 'id',
        'services.microsoft.client_secret' => 'secret',
        'services.microsoft.directory' => null,
    ]);

    // Entra's directory is part of its issuer, and an id_token names the directory that
    // issued it. Without one there is no issuer to pin, so the assertion could not be
    // checked at all — the provider is dropped rather than offered unverifiably.
    expect($providers->find('microsoft'))->toBeNull();

    config(['services.microsoft.directory' => '72f988bf-86f1-41af-91ab-2d7cd011db47']);

    expect($providers->find('microsoft')?->issuer())
        ->toBe('https://login.microsoftonline.com/72f988bf-86f1-41af-91ab-2d7cd011db47/v2.0');
})->group('security');

it('refuses a catalogue provider this path cannot drive', function () {
    // Apple's secret is a per-request signed JWT and it POSTs its callback; Discord is
    // simply not on the operator's list. A key that is not named is not offered —
    // deny by default, rather than a button that cannot complete.
    config(['services.apple.client_id' => 'id', 'services.apple.client_secret' => 'secret']);
    config(['services.discord.client_id' => 'id', 'services.discord.client_secret' => 'secret']);

    expect(app(OperatorProviders::class)->find('apple'))->toBeNull()
        ->and(app(OperatorProviders::class)->find('discord'))->toBeNull()
        ->and(app(OperatorProviders::class)->find('nonsense'))->toBeNull();
})->group('security');

it('404s a provider the deployment does not offer', function () {
    config(['services.google.client_id' => null]);

    $this->get('/auth/google/redirect')->assertNotFound();
    $this->get('/auth/google/callback?code=x&state=y')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The authorization request
|--------------------------------------------------------------------------
*/

it('builds Google an authorization request from its own discovery document', function () {
    fakeGoogleDiscovery();

    $response = $this->get('/auth/google/redirect');
    $url = $response->headers->get('Location') ?? '';

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['response_type'] ?? null)->toBe('code')
        ->and($query['client_id'] ?? null)->toBe('google-client-id')
        ->and($query['redirect_uri'] ?? null)->toBe(route('social.callback', 'google'))
        ->and($query['scope'] ?? null)->toBe('openid email profile')
        ->and($query['state'] ?? null)->not->toBeEmpty()
        ->and($query['nonce'] ?? null)->not->toBeEmpty();
});

it('sends GitHub to the endpoint the catalogue names, with the scopes it names', function () {
    config([
        'services.github.client_id' => 'gh-id',
        'services.github.client_secret' => 'gh-secret',
        'cbox-id.federation.verify_url' => false,
    ]);

    $url = $this->get('/auth/github/redirect')->headers->get('Location') ?? '';
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    // The endpoints come from the catalogue, never from configuration — an operator who
    // could type them could point a button labelled "GitHub" at a host of their choosing.
    expect($url)->toStartWith('https://github.com/login/oauth/authorize?')
        // `user:email` is not optional: GitHub hides the address by default, so without
        // it a majority of sign-ins would arrive with no email at all.
        ->and($query['scope'] ?? null)->toBe('read:user user:email')
        ->and($query['state'] ?? null)->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Completing a sign-in
|--------------------------------------------------------------------------
*/

it('signs in a new user against a real signed id_token', function () {
    $stash = googleSignInInProgress();

    $this->get('/auth/google/callback?code=auth-code&state='.$stash['state'])
        ->assertRedirect(route('account'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeTrue();

    // Stored under the provider string every operator-level identity has always used.
    // A client that labelled this `oidc` would quietly orphan every existing link.
    $subject = app(Subjects::class)->findByEmail('newcomer@example.test');
    expect($subject)->not->toBeNull()
        ->and(collect(app(Subjects::class)->linkedIdentities($subject->id))
            ->contains(fn ($i): bool => $i->provider === 'social:google' && $i->subject === 'google|12345'))
        ->toBeTrue();
});

it('refuses an id_token signed by the wrong key', function () {
    // The token is correct in every other way — right issuer, right audience, right
    // nonce — and served with a JWKS from a DIFFERENT keypair. Only the signature is
    // wrong, so this fails for exactly one reason.
    $stash = googleSignInInProgress(jwks: googleIdToken()['jwks']);

    $this->get('/auth/google/callback?code=auth-code&state='.$stash['state'])
        ->assertRedirect(route('login'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
})->group('security');

/*
|--------------------------------------------------------------------------
| The guards the old driver kept out of sight
|--------------------------------------------------------------------------
*/

it('refuses a callback whose state does not match', function () {
    googleSignInInProgress();

    // Without this check the callback is an unauthenticated URL that signs somebody in:
    // an attacker who can make a browser fetch it logs that browser into an account of
    // the attacker's choosing.
    $this->get('/auth/google/callback?code=auth-code&state=not-the-issued-state')
        ->assertRedirect(route('login'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
})->group('security');

it('refuses a callback with no state at all', function () {
    googleSignInInProgress();

    $this->get('/auth/google/callback?code=auth-code')->assertRedirect(route('login'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
})->group('security');

it('refuses a replayed callback', function () {
    $stash = googleSignInInProgress();

    $this->get('/auth/google/callback?code=auth-code&state='.$stash['state'])
        ->assertRedirect(route('account'));

    session()->forget(PlatformAuth::SESSION_KEY);

    // The stash is PULLED, not read, so the same callback cannot be walked twice.
    $this->get('/auth/google/callback?code=auth-code&state='.$stash['state'])
        ->assertRedirect(route('login'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
})->group('security');

it('refuses an id_token whose nonce is not the one it issued', function () {
    $stash = googleSignInInProgress(['nonce' => 'a-nonce-from-somewhere-else']);

    // The nonce binds the token to THIS browser's authorization request. Without it a
    // valid id_token obtained elsewhere replays into this callback.
    $this->get('/auth/google/callback?code=auth-code&state='.$stash['state'])
        ->assertRedirect(route('login'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
})->group('security');

it('puts every outbound call through the SSRF gate', function () {
    // A guard that allows everything but RECORDS what it was asked about. Asserting the
    // gate was consulted for each endpoint is the direct claim; asserting only that a
    // blocking guard breaks the flow would also pass if the flow broke for some unrelated
    // reason, which is precisely how a bypass would hide.
    $seen = new ArrayObject;

    app()->bind(UrlGuard::class, fn () => new class($seen) implements UrlGuard
    {
        public function __construct(private ArrayObject $seen) {}

        public function assertSafe(string $url, ?array $allowedSchemes = null, bool $allowCredentials = false): void
        {
            $this->seen->append($url);
        }

        public function isSafe(string $url, ?array $allowedSchemes = null, bool $allowCredentials = false): bool
        {
            $this->seen->append($url);

            return true;
        }

        public function assertSafeRedirect(string $url, ?array $allowedSchemes = null, bool $allowCredentials = false): void
        {
            $this->seen->append($url);
        }

        public function pinnedOptions(string $url, ?array $allowedSchemes = null, bool $allowCredentials = false): array
        {
            $this->seen->append($url);

            return [];
        }
    });

    $stash = googleSignInInProgress();
    config(['cbox-id.federation.verify_url' => true]);

    $this->get('/auth/google/callback?code=auth-code&state='.$stash['state'])
        ->assertRedirect(route('account'));

    // The token exchange carries our client secret and the JWKS decides whether a
    // signature is trusted. Neither may be fetched from an unvetted address, and the
    // driver these providers used to run on vetted nothing at all.
    expect(iterator_to_array($seen))
        ->toContain('https://oauth2.googleapis.com/token')
        ->toContain('https://www.googleapis.com/oauth2/v3/certs');
})->group('security');

/*
|--------------------------------------------------------------------------
| What must not regress
|--------------------------------------------------------------------------
*/

it('never merges into an existing account by email', function () {
    // The address already belongs to somebody, and the provider's word for it is a claim,
    // not proof. The sign-in must not reach that account — it is held for an explicit,
    // authenticated confirmation instead.
    $existing = app(Subjects::class)->create('newcomer@example.test', 'Existing', 'supersecret123');

    $stash = googleSignInInProgress();

    $this->get('/auth/google/callback?code=auth-code&state='.$stash['state'])
        ->assertRedirect(route('login'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse()
        ->and(app(Subjects::class)->linkedIdentities($existing->id))->toBeEmpty();

    // Held aside, so the legitimate owner is not dead-ended.
    expect(app(PlatformAuth::class)->pendingLink())->not->toBeNull();
})->group('security');

it('refuses a social sign-in when the subject\'s organization mandates SSO', function () {
    // The least arguable of the mandate's doors. Every other one is about how strong a
    // local factor is; this authenticates against a DIFFERENT identity provider than the
    // mandated one, which is the exact thing the mandate exists to decide. These are the
    // OPERATOR's buttons rather than the organization's connection, so there is no reading
    // on which "require SSO" points here.
    // Linked up front rather than by signing in twice: the fixtures stub the token
    // endpoint per flow and Http::fake() merges stubs first-match-wins, so a second
    // sign-in in one test replays the first flow's nonce and fails for the wrong reason.
    // This is the state a real second visit is in — an identity linked on an earlier one.
    $subject = app(Subjects::class)->create('newcomer@example.test', 'New Comer', 'supersecret123');
    app(Subjects::class)->link(
        $subject->id,
        new FederatedPrincipal('social:google', 'google|12345', 'newcomer@example.test', 'New Comer'),
    );

    // That identity belongs to somebody whose company has chosen its own directory.
    $org = app(Organizations::class)->create(new NewOrganization('Directory Co', 'directory-co'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    $connection = app(Connections::class)->create(
        $org->id,
        ConnectionType::Oidc,
        'Directory IdP',
        ['issuer' => 'https://idp.directory.test', 'client_id' => 'abc', 'client_secret' => 's', 'signing_key' => 'k'],
    );
    app(Connections::class)->activate($org->id, $connection->id);
    // The provider still says yes, and without a mandate this signs them straight in —
    // proven by every other test in this file.
    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    $stash = googleSignInInProgress();
    $this->get('/auth/google/callback?code=auth-code&state='.$stash['state'])
        ->assertRedirect(route('login'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse()
        // Not the "already taken, link it from Settings" hold — that is a different
        // refusal with a different screen, and it would satisfy the redirect above.
        ->and(app(PlatformAuth::class)->pendingLink())->toBeNull();

    nextRequest();
    $this->get(route('login'))
        ->assertSee('Directory Co requires single sign-on')
        ->assertSee(url("/sso/oidc/{$connection->id}/redirect"));
})->group('security');

it('keeps the held identity labelled by the catalogue', function () {
    app(PlatformAuth::class)->startPendingLink(
        new FederatedPrincipal('social:github', 'gh|1', 'someone@example.test', 'Someone')
    );

    // "GitHub", not "Github" — the screen asking a security question should not look
    // like it was assembled by ucfirst().
    expect(app(PlatformAuth::class)->pendingLink()?->label())->toBe('GitHub');
});
