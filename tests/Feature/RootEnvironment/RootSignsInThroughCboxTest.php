<?php

declare(strict_types=1);

use App\Actions\Sso\SsoFields;
use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlatformAuth;
use App\Platform\SignInThrough;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\RootGoogle;

/*
|--------------------------------------------------------------------------
| THE PLATFORM ROOT SIGNS IN THROUGH THE `cbox` ENVIRONMENT
|--------------------------------------------------------------------------
|
| On the hosted service the root's console is one of Cbox's own apps, and every other one
| (Billing, Brain, Engage…) signs in through the `cbox` environment. So the root connects
| to that environment by OpenID Connect, environment-wide, and names the connection as the
| one it signs in through: `/login` stops being a page and becomes a redirect there, and
| somebody already signed in to Billing arrives at the console signed in.
|
| `/login/direct` is the root's own page, always — the operators' way in when the `cbox`
| environment is the thing that is broken.
|
| The `cbox` environment is faked as a real OpenID Provider: discovery, a token endpoint
| that answers with an RS256 id_token carrying the nonce the root issued, and the JWKS that
| verifies it.
*/

const RSC_HOST = RootGoogle::HOST;
const RSC_ISSUER = 'https://cbox.cboxid.com';

beforeEach(function (): void {
    RootGoogle::deployment();

    Http::fake([
        'cbox.cboxid.com/.well-known/openid-configuration' => Http::response([
            'issuer' => RSC_ISSUER,
            'authorization_endpoint' => RSC_ISSUER.'/oauth/authorize',
            'token_endpoint' => RSC_ISSUER.'/oauth/token',
            'jwks_uri' => RSC_ISSUER.'/oauth/jwks',
        ]),
    ]);

    $this->account = provisionAccount('owner@acme.example');
    $this->tenantHost = $this->account['environment']->slug.'.'.RSC_HOST;
});

/** The platform root's environment, refreshed. */
function rscRoot(): Environment
{
    return app(PlatformRoot::class)->model()->refresh();
}

/** Who the browser is signed in to the root as, or null. */
function rscSignedInSubject(): ?string
{
    $sessionId = session(PlatformAuth::SESSION_KEY);

    return is_string($sessionId)
        ? app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->active($sessionId)?->user_id)
        : null;
}

/**
 * An environment-wide OpenID Connect connection to the `cbox` environment in the CURRENT
 * environment, discovered the way `sso.connections.create` discovers one.
 */
function rscCreateConnection(string $name = 'Cbox', bool $activate = true): Connection
{
    $connections = app(Connections::class);
    $connection = $connections->create(null, ConnectionType::Oidc, $name, SsoFields::discovered([
        'issuer' => RSC_ISSUER,
        'client_id' => 'cid_app_cboxid_com',
        'client_secret' => 'the-client-secret',
    ]));

    if ($activate) {
        $connections->activate(null, $connection->id);
    }

    return $connection->refresh();
}

/**
 * What an operator does once: connect the root to the `cbox` environment, environment-wide,
 * activate it, and make it what the root signs in through.
 */
function rscConnectCbox(): Connection
{
    RootGoogle::operatorSignedIn();

    return app(PlatformRoot::class)->run(function (): Connection {
        $connection = rscCreateConnection();
        app(SignInThrough::class)->set(app(PlatformRoot::class)->model(), $connection->id);

        return $connection;
    });
}

/**
 * The `cbox` environment's token endpoint answers with an id_token for $sub, signed by a key
 * its JWKS publishes.
 *
 * @param  array<string, mixed>  $claims
 */
function rscFakeTokenEndpoint(string $sub, string $email, string $nonce, array $claims = []): void
{
    // ONE KEY PER PROCESS, and the stubs read the CURRENT token: `Http::fake()` keeps every
    // stub a test registered and the first match wins, so a second sign-in in the same test
    // would otherwise be answered with the first one's token (and its nonce).
    static $pem = null;
    static $jwks = [];

    if ($pem === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);
        $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $jwks = [['kty' => 'RSA', 'kid' => 'cbox-1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]];
    }

    $GLOBALS['rsc_id_token'] = JWT::encode([
        'iss' => RSC_ISSUER,
        'aud' => 'cid_app_cboxid_com',
        'sub' => $sub,
        'email' => $email,
        'email_verified' => true,
        'name' => 'Cbox Person',
        'nonce' => $nonce,
        'iat' => time(),
        'exp' => time() + 300,
        ...$claims,
    ], $pem, 'RS256', 'cbox-1');

    $keys = $jwks;

    Http::fake([
        'cbox.cboxid.com/oauth/token' => static fn () => Http::response(['id_token' => $GLOBALS['rsc_id_token']]),
        'cbox.cboxid.com/oauth/jwks' => static fn () => Http::response(['keys' => $keys]),
    ]);
}

/**
 * Follow `/login` (or any page that bounces there) to the `cbox` environment and back as
 * $sub — the silent hop of somebody already signed in there. Returns the callback's answer.
 */
function rscHop(string $sub, string $email, string $from = 'https://'.RSC_HOST.'/login', array $claims = []): TestResponse
{
    $location = $from;

    // The console page → /login → /sso/oidc/{id}/redirect → cbox.cboxid.com/oauth/authorize.
    for ($i = 0; $i < 5 && ! str_starts_with($location, RSC_ISSUER); $i++) {
        $response = test()->get($location);

        foreach ($response->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), '__Host-cbox_fed_')) {
                test()->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue());
            }
        }

        $location = (string) $response->headers->get('Location');
        expect($location)->not->toBe('', 'the hop stopped at '.$response->status());
    }

    expect($location)->toStartWith(RSC_ISSUER.'/oauth/authorize?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    rscFakeTokenEndpoint($sub, $email, (string) $query['nonce'], $claims);

    return test()->get((string) $query['redirect_uri'].'?'.http_build_query(['code' => 'the-code', 'state' => (string) $query['state']]));
}

it('sends a signed-out visitor straight from the console to the cbox environment, with the root\'s client and redirect URI', function (): void {
    $connection = rscConnectCbox();
    RootGoogle::signedOutBrowser();

    // The console, signed out: to the sign-in…
    $admin = $this->get('https://'.RSC_HOST.'/admin/environment');
    expect((string) $admin->headers->get('Location'))->toBe('https://'.RSC_HOST.'/login');

    // …which is not a page any more: straight to the connection's start…
    $this->get('https://'.RSC_HOST.'/login')->assertRedirect('https://'.RSC_HOST.'/sso/oidc/'.$connection->id.'/redirect');

    // …and from there to the cbox environment's authorization endpoint.
    $authorize = (string) $this->get('https://'.RSC_HOST.'/sso/oidc/'.$connection->id.'/redirect')->headers->get('Location');
    parse_str((string) parse_url($authorize, PHP_URL_QUERY), $query);

    expect($authorize)->toStartWith(RSC_ISSUER.'/oauth/authorize?')
        ->and($query['client_id'])->toBe('cid_app_cboxid_com')
        ->and($query['redirect_uri'])->toBe('https://'.RSC_HOST.'/sso/oidc/'.$connection->id.'/callback')
        // Cbox ID requires PKCE of every client, so the root sends a challenge…
        ->and($query['code_challenge_method'] ?? null)->toBe('S256')
        ->and(strlen((string) ($query['code_challenge'] ?? '')))->toBe(43);
});

it('proves the PKCE challenge with its verifier at the cbox environment\'s token endpoint', function (): void {
    rscConnectCbox();
    RootGoogle::signedOutBrowser();

    $location = (string) $this->get('https://'.RSC_HOST.'/login')->headers->get('Location');
    $start = $this->get($location);

    foreach ($start->headers->getCookies() as $cookie) {
        if (str_starts_with($cookie->getName(), '__Host-cbox_fed_')) {
            $this->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue());
        }
    }

    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);

    // The token endpoint keeps what it was sent (registered first: the first stub wins).
    $sent = null;
    Http::fake(['cbox.cboxid.com/oauth/token' => function ($request) use (&$sent) {
        $sent = $request->data();

        return Http::response(['id_token' => $GLOBALS['rsc_id_token']]);
    }]);
    rscFakeTokenEndpoint('usr_cbox_pkce', 'pkce@example.com', (string) $query['nonce']);

    $this->get((string) $query['redirect_uri'].'?'.http_build_query(['code' => 'the-code', 'state' => (string) $query['state']]));

    // …and the verifier whose S256 hash it was, on the token request.
    $verifier = (string) ($sent['code_verifier'] ?? '');

    expect($verifier)->not->toBe('')
        ->and(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))->toBe((string) $query['code_challenge']);
    expect(rscSignedInSubject())->not->toBeNull();
});

it('is one action, recorded, that refuses a connection which cannot sign anybody in', function (): void {
    // The action is the environment's, on any environment: proven here on a tenant's, with
    // a management key, because that is the door an operator's tooling uses too.
    $environment = $this->account['environment'];
    serveOnTestHost($environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    $key = app(EnvironmentApiKeys::class)->issue($environment->id, 'Sign-in worker', ['signin:write'])->plaintext;

    $active = rscCreateConnection();
    $draft = rscCreateConnection('Draft', activate: false);

    $this->withToken($key)->putJson('/api/v1/sign-in/through', ['connection_id' => $active->id])
        ->assertOk()
        ->assertJsonPath('data.connection_id', $active->id)
        ->assertJsonPath('data.connection_name', 'Cbox');

    expect(SignInThrough::configuredFor($environment->refresh()))->toBe($active->id)
        ->and(AuditEntry::query()->where('action', 'auth_policy.sign_in_through_updated')->exists())->toBeTrue();

    // Not one of this environment's: a 404. A draft: refused, and nothing changes.
    $this->withToken($key)->putJson('/api/v1/sign-in/through', ['connection_id' => 'nope'])->assertNotFound();
    $this->withToken($key)->putJson('/api/v1/sign-in/through', ['connection_id' => $draft->id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'connection_not_eligible');

    expect(SignInThrough::configuredFor($environment->refresh()))->toBe($active->id);

    // Null: the environment's own sign-in page again.
    $this->withToken($key)->putJson('/api/v1/sign-in/through', ['connection_id' => null])->assertOk();
    expect(SignInThrough::configuredFor($environment->refresh()))->toBeNull();
});

it('goes back to the root\'s own page when the connection it signs in through is switched off', function (): void {
    $connection = rscConnectCbox();
    app(PlatformRoot::class)->run(fn () => Connection::query()->whereKey($connection->id)->update(['status' => ConnectionStatus::Inactive]));
    RootGoogle::signedOutBrowser();

    $this->get('https://'.RSC_HOST.'/login')->assertOk();
});

it('links the root account to the cbox user by their stable sub, not by the address', function (): void {
    $connection = rscConnectCbox();

    // Somebody whose root account is already linked to their cbox user — and whose address
    // there has since changed. The sub is what decides who they are.
    $subjectId = app(PlatformRoot::class)->run(function () use ($connection): string {
        $subject = app(Subjects::class)->create('sylvester@old-address.example', 'Sylvester');
        app(Subjects::class)->link($subject->id, new FederatedPrincipal(
            subject: 'usr_cbox_sylvester',
            email: 'sylvester@old-address.example',
            name: 'Sylvester',
            provider: 'oidc',
            connectionId: $connection->id,
        ));

        return $subject->id;
    });

    RootGoogle::signedOutBrowser();

    $callback = rscHop('usr_cbox_sylvester', 'sylvester@new-address.example');

    expect($callback->status())->toBe(302)
        ->and(session(PlatformAuth::SESSION_KEY))->not->toBeNull();

    $this->get('https://'.RSC_HOST.'/settings')->assertOk();
    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('sylvester@new-address.example')))->toBeNull();
    expect(rscSignedInSubject())->toBe($subjectId);
})->group('security');

it('provisions a root account for a cbox user on their first sign-in, and finds it again by sub', function (): void {
    rscConnectCbox();
    RootGoogle::signedOutBrowser();

    rscHop('usr_cbox_newcomer', 'newcomer@example.com');

    $subject = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('newcomer@example.com'));
    expect($subject)->not->toBeNull()
        ->and(session(PlatformAuth::SESSION_KEY))->not->toBeNull();

    RootGoogle::signOut();

    // Same sub, a different address: the same account.
    rscHop('usr_cbox_newcomer', 'renamed@example.com');

    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('renamed@example.com')))->toBeNull()
        ->and(rscSignedInSubject())->toBe($subject?->id);
});

it('keeps the operators\' direct door working while the cbox environment cannot be reached', function (): void {
    $connection = rscConnectCbox();
    RootGoogle::signedOutBrowser();

    // The cbox environment is down: nothing it serves answers.
    Http::fake(['cbox.cboxid.com/*' => fn () => throw new ConnectionException('cbox.cboxid.com is down')]);

    // /login/direct is the root's own sign-in page, with its own methods, and never leaves.
    $direct = $this->get('https://'.RSC_HOST.'/login/direct')->assertOk();
    expect($direct->inertiaPage()['component'])->toBe('auth/login')
        ->and($direct->inertiaProps('methods.passkeys'))->toBeTrue();

    // The old operator address goes there too.
    $this->get('https://'.RSC_HOST.'/operator/login')->assertRedirect('/login/direct');

    // The operator signs in with their own credential there: on to their second factor,
    // without the cbox environment being asked anything.
    $signedIn = $this->from('https://'.RSC_HOST.'/login/direct')
        ->post('https://'.RSC_HOST.'/login', ['email' => 'ops@platform.test', 'password' => 'a-strong-operator-pass'])
        ->assertSessionHasNoErrors();

    expect((string) $signedIn->headers->get('Location'))->toEndWith('/mfa');

    // And somebody who took the ordinary way, whose sign-in the cbox environment could not
    // finish, lands back on the root's page with the problem said — not in a loop between
    // the two doors — and the button to try again on it.
    RootGoogle::signedOutBrowser();

    $authorize = (string) $this->get('https://'.RSC_HOST.'/sso/oidc/'.$connection->id.'/redirect')->headers->get('Location');
    parse_str((string) parse_url($authorize, PHP_URL_QUERY), $query);

    $this->get((string) $query['redirect_uri'].'?'.http_build_query(['code' => 'the-code', 'state' => (string) $query['state']]))
        ->assertRedirect('https://'.RSC_HOST.'/login');

    $page = $this->get('https://'.RSC_HOST.'/login')->assertOk();
    expect($page->inertiaPage()['component'])->toBe('auth/login')
        ->and(collect($page->inertiaProps('providers'))->first()['url'] ?? null)->toBe('https://'.RSC_HOST.'/sso/oidc/'.$connection->id.'/redirect');
});

it('still lands on a tenant\'s console after signing in through the cbox environment', function (): void {
    rscConnectCbox();
    RootGoogle::signedOutBrowser();

    // An admin of the tenant's workspace, whose root account is the one the cbox user links to.
    $subjectId = $this->account['subjectId'];
    $connection = app(PlatformRoot::class)->run(fn (): Connection => Connection::query()->sole());
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->link($subjectId, new FederatedPrincipal(
        subject: 'usr_cbox_owner',
        email: 'owner@acme.example',
        name: 'Owner',
        provider: 'oidc',
        connectionId: $connection->id,
    )));

    // 1. The tenant's console's old address, signed out: to the console on the root.
    $console = 'https://'.RSC_HOST.'/'.app(EnvironmentConsolePath::class)->for($this->account['environment']->id).'/users';
    $this->get('https://'.$this->tenantHost.'/admin/users')->assertRedirect($console);

    // 2. Through the cbox environment and back, to the page they asked for.
    $callback = rscHop('usr_cbox_owner', 'owner@acme.example', from: $console);
    expect((string) $callback->headers->get('Location'))->toBe($console);

    // 3. The page, in the session that just signed in.
    $this->get($console)->assertOk();
    expect(app(EnvironmentAdminAuth::class)->subjectId())->toBe($subjectId);
});

it('signs out of the console without signing anybody out of the cbox environment, and offers the way back', function (): void {
    rscConnectCbox();
    RootGoogle::signedOutBrowser();

    rscHop('usr_cbox_someone', 'someone@example.com');
    expect(session(PlatformAuth::SESSION_KEY))->not->toBeNull();

    $out = $this->post('https://'.RSC_HOST.'/logout');

    expect(session(PlatformAuth::SESSION_KEY))->toBeNull()
        ->and((string) $out->headers->get('Location'))->toContain('/signed-out');

    // Nothing was sent to the cbox environment: its session is its own.
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'cbox.cboxid.com/oauth/logout')
        || str_contains((string) $request->url(), 'cbox.cboxid.com/logout'));
});

it('keeps an operator the operator, with no second prompt when they used a passkey at the cbox environment', function (): void {
    $connection = rscConnectCbox();
    $operatorSubject = RootGoogle::operatorSignedIn();

    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->link($operatorSubject, new FederatedPrincipal(
        subject: 'usr_cbox_ops',
        email: 'ops@platform.test',
        name: 'Ops',
        provider: 'oidc',
        connectionId: $connection->id,
    )));

    // Signed in at the cbox environment with a passkey: AAL2 there, so AAL2 here.
    RootGoogle::signedOutBrowser();
    rscHop('usr_cbox_ops', 'ops@platform.test', claims: ['amr' => ['passkey', 'made-up']]);

    expect(rscSignedInSubject())->toBe($operatorSubject);
    $this->get('https://'.RSC_HOST.'/admin/environments')->assertOk();

    $amr = app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->active((string) session(PlatformAuth::SESSION_KEY))?->amr);
    expect($amr)->toContain('sso')->toContain('passkey')->not->toContain('made-up');

    // With only a password there, the operator proves a second factor here, as always.
    RootGoogle::signedOutBrowser();
    rscHop('usr_cbox_ops', 'ops@platform.test', claims: ['amr' => ['pwd']]);

    expect((string) $this->get('https://'.RSC_HOST.'/admin/environments')->headers->get('Location'))->toContain('/mfa');
});
