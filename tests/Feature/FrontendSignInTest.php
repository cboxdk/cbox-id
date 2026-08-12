<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\FrontendApi\LoginTicket;
use App\Platform\FrontendApi\LoginTickets;
use App\Platform\FrontendApi\SignInWithTicket;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\FrontendApiServiceProvider;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * THE MOST DANGEROUS SURFACE IN THE PRODUCT: an unauthenticated caller offering a password,
 * from a browser, cross-origin. Every test here is a way that could go wrong.
 */
beforeEach(function (): void {
    $this->app['config']->set('cbox-id.frontend_api.enabled', true);
    (new FrontendApiServiceProvider($this->app))->boot();

    RateLimiter::clear('cbox-frontend-signin:'.hash('sha256', 'ada@acme.test'));

    $this->key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://app.acme.test']);
    $this->subject = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
});

function frontendSignIn(array $credentials): TestResponse
{
    return test()->withHeaders([
        'X-Cbox-Publishable-Key' => test()->key->key,
        'Origin' => 'https://app.acme.test',
    ])->postJson('/frontend/v1/sign-in', $credentials);
}

it('hands back a ticket, never a token', function (): void {
    $response = frontendSignIn(['email' => 'ada@acme.test', 'password' => 'a-strong-unbreached-passphrase'])
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    $body = $response->json();

    expect($body)->toHaveKey('login_ticket')
        // Giving tokens to a page that proved a password is the implicit grant, which
        // OAuth 2.1 removes. Nothing token-shaped may appear here.
        ->and($body)->not->toHaveKey('access_token')
        ->and($body)->not->toHaveKey('id_token')
        ->and($body)->not->toHaveKey('refresh_token')
        ->and($body)->not->toHaveKey('code');
});

/**
 * A wrong password, an unknown address and a locked account are ONE answer. Telling them
 * apart is the enumeration oracle, and a public endpoint is the easiest place to leak it.
 */
it('answers the same for a wrong password and an address it has never seen', function (): void {
    $wrong = frontendSignIn(['email' => 'ada@acme.test', 'password' => 'not-the-password']);
    $unknown = frontendSignIn(['email' => 'nobody@acme.test', 'password' => 'not-the-password']);

    expect($wrong->status())->toBe($unknown->status())
        ->and($wrong->json())->toBe($unknown->json());
});

it('refuses a caller with no publishable key, before it looks at the password', function (): void {
    $this->postJson('/frontend/v1/sign-in', [
        'email' => 'ada@acme.test',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertStatus(401);

    expect(LoginTicket::query()->count())->toBe(0);
});

it('refuses a valid key used from an origin nobody allow-listed', function (): void {
    $this->withHeaders([
        'X-Cbox-Publishable-Key' => $this->key->key,
        'Origin' => 'https://evil.test',
    ])->postJson('/frontend/v1/sign-in', [
        'email' => 'ada@acme.test',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertStatus(401);

    expect(LoginTicket::query()->count())->toBe(0);
});

/**
 * The channel's own limiter is per KEY, which is the wrong unit: an attacker spreading
 * guesses across pages holding one key sits under it. This one defends the ADDRESS.
 */
it('rate limits guesses against one address, whoever is asking', function (): void {
    foreach (range(1, 10) as $ignored) {
        frontendSignIn(['email' => 'ada@acme.test', 'password' => 'wrong']);
    }

    frontendSignIn(['email' => 'ada@acme.test', 'password' => 'wrong'])
        ->assertStatus(429)
        ->assertJsonPath('status', 'rate_limited');
});

/**
 * SINGLE USE, enforced by a conditional update rather than read-then-write. A ticket that
 * redeems twice is a session an attacker gets by replaying a URL out of history.
 */
it('redeems a ticket exactly once', function (): void {
    $ticket = app(LoginTickets::class)->mint($this->key, $this->subject->id, ['pwd']);

    expect(app(LoginTickets::class)->redeem($ticket))->not->toBeNull()
        ->and(app(LoginTickets::class)->redeem($ticket))->toBeNull();
});

it('refuses a ticket that has expired', function (): void {
    $ticket = app(LoginTickets::class)->mint($this->key, $this->subject->id, ['pwd']);

    LoginTicket::query()->update(['expires_at' => now()->subMinute()]);

    expect(app(LoginTickets::class)->redeem($ticket))->toBeNull();
});

it('keeps only a hash of the ticket', function (): void {
    $ticket = app(LoginTickets::class)->mint($this->key, $this->subject->id, ['pwd']);

    // A database leak must not hand somebody a working sign-in.
    expect(LoginTicket::query()->where('token_hash', $ticket)->exists())->toBeFalse()
        ->and(LoginTicket::query()->first()?->token_hash)->toBe(hash('sha256', $ticket));
});

/**
 * The methods travel with the ticket. Re-deriving them would mean guessing, and a guess
 * that says "pwd" for a passkey sign-in produces a session whose acr understates it.
 */
it('carries the authentication methods through to the session it creates', function (): void {
    $ticket = app(LoginTickets::class)->mint($this->key, $this->subject->id, ['pwd', 'webauthn']);

    expect(app(LoginTickets::class)->redeem($ticket)?->amr)->toBe(['pwd', 'webauthn']);
});

it('refuses an empty email or password without consulting the auth stack', function (array $body): void {
    frontendSignIn($body)->assertStatus(401);

    expect(LoginTicket::query()->count())->toBe(0);
})->with([
    'no password' => [['email' => 'ada@acme.test']],
    'no email' => [['password' => 'x']],
    'both blank' => [['email' => '', 'password' => '']],
]);

/**
 * THE WHOLE CHAIN. Without this the endpoint is a dead end: a page gets a ticket and has
 * nowhere to spend it, and every test above passes.
 */
it('turns a ticket into a session at the authorize endpoint', function (): void {
    $ticket = frontendSignIn(['email' => 'ada@acme.test', 'password' => 'a-strong-unbreached-passphrase'])
        ->assertOk()
        ->json('login_ticket');

    expect(app(CurrentUser::class)->check())->toBeFalse();

    app(SignInWithTicket::class)->establish(request(), $ticket);

    expect(session()->get(PlatformAuth::SESSION_KEY))->not->toBeNull();
});

/**
 * A ticket minted in one environment must not sign anybody in elsewhere. Redemption reads
 * under whatever scope it is given, so the bind is checked where the environment is known.
 */
it('refuses a ticket that belongs to another environment', function (): void {
    $ticket = app(LoginTickets::class)->mint($this->key, $this->subject->id, ['pwd']);

    LoginTicket::query()->update(['environment_id' => 'env_somewhere_else']);

    expect(app(SignInWithTicket::class)->establish(request(), $ticket))->toBeFalse()
        ->and(session()->get(PlatformAuth::SESSION_KEY))->toBeNull();
});

it('refuses a ticket for a subject who has since been deleted', function (): void {
    $ticket = app(LoginTickets::class)->mint($this->key, $this->subject->id, ['pwd']);

    LoginTicket::query()->update(['subject_id' => '01aaaaaaaaaaaaaaaaaaaaaaaa']);

    expect(app(SignInWithTicket::class)->establish(request(), $ticket))->toBeFalse();
});

/**
 * A TICKET-CREATED SESSION MUST BE THE SAME SESSION THE HOSTED FORM CREATES.
 *
 * It was not: `sessions->start()` produced one with no organization, no IP or user-agent
 * for adaptive risk, no rotated id — and, worst, a surviving sudo confirmation, because
 * only `establish()` forgets it. Minting a session is exactly the moment a prior step-up
 * should stop being established.
 */
it('drops a prior step-up when a ticket mints a session', function (): void {
    $ticket = app(LoginTickets::class)->mint($this->key, $this->subject->id, ['pwd']);

    session()->put(Sudo::SESSION_KEY, now()->timestamp);

    app(SignInWithTicket::class)->establish(request(), $ticket);

    expect(session()->get(Sudo::SESSION_KEY))->toBeNull();
});

it('pins an organization on a ticket-created session, as the hosted form does', function (): void {
    $org = app(Organizations::class)
        ->create(new NewOrganization('Acme', 'acme-ticket'));
    app(Memberships::class)
        ->add($org->id, $this->subject->id, MembershipRole::Owner);

    $ticket = app(LoginTickets::class)->mint($this->key, $this->subject->id, ['pwd']);

    app(SignInWithTicket::class)->establish(request(), $ticket);

    $sessionId = session()->get(PlatformAuth::SESSION_KEY);
    $session = app(SessionManager::class)->active((string) $sessionId);

    // Without a pinned organization the console, the entitlement reads and the org scope
    // all resolve to nothing.
    expect($session?->organization_id)->toBe($org->id);
});

/**
 * THE CHAIN OVER REAL HTTP — a ticket presented to the route, not to the class behind it.
 *
 * The test above calls `SignInWithTicket::establish()` directly, so it holds the class's
 * behaviour and nothing about the wiring. Renaming the query parameter, or moving the
 * redemption after the session check, kills the feature with that test still green. This
 * one drives `GET /oauth/authorize?...&login_ticket=` exactly as a browser does.
 */
it('signs a person in at the authorize route itself, from the ticket in the URL', function (): void {
    $registered = app(ClientRegistry::class)->register(new NewClient(
        'RP',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ));

    $ticket = frontendSignIn(['email' => 'ada@acme.test', 'password' => 'a-strong-unbreached-passphrase'])
        ->assertOk()
        ->json('login_ticket');

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $registered->client->client_id,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid',
        'state' => 'st',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
        'login_ticket' => $ticket,
    ]))->assertOk()
        // Not the sign-in page: the ticket WAS the sign-in.
        ->assertDontSee('name="password"', false);

    expect(session()->get(PlatformAuth::SESSION_KEY))->not->toBeNull();
});

/**
 * THE WRONG-PRINCIPAL BUG. Redemption used to sit inside `if (! signed in)`, so a browser
 * already holding Alice's session ignored a ticket that had just authenticated Bob: the
 * flow ran as Alice, skipped consent for a first-party client, and handed the relying party
 * an id_token for somebody who never signed in on that page. Bob's ticket went unspent and
 * nothing anywhere reported a mismatch.
 */
it('lets the ticket decide who is signing in, not the cookie that was already there', function (): void {
    $registered = app(ClientRegistry::class)->register(new NewClient(
        'RP',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ));

    $alice = app(Subjects::class)->create('alice@acme.test', 'Alice', 'a-strong-unbreached-passphrase');

    $bobsTicket = app(LoginTickets::class)->mint($this->key, $this->subject->id, ['pwd']);

    $this->withSession([PlatformAuth::SESSION_KEY => app(SessionManager::class)->start($alice->id, null, ['pwd'])->id])
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $registered->client->client_id,
            'redirect_uri' => 'https://app.test/cb',
            'response_type' => 'code',
            'scope' => 'openid',
            'state' => 'st',
            'code_challenge' => 'abc',
            'code_challenge_method' => 'S256',
            'login_ticket' => $bobsTicket,
        ]))->assertOk();

    $sessionId = session()->get(PlatformAuth::SESSION_KEY);

    expect(app(SessionManager::class)->active(is_string($sessionId) ? $sessionId : '')?->user_id)
        ->toBe($this->subject->id);
});

/**
 * OIDC Core §3.1.2.1 lets `prompt=none` succeed only when the End-User "is already
 * authenticated". A ticket creates a session inside the request — no UI is shown, so the
 * letter about interaction is kept, but the precondition is not, and an SPA reading a
 * successful silent renew as proof of a pre-existing SSO session would be believing
 * something we invented milliseconds ago on a page it does not control.
 */
it('will not let a ticket satisfy prompt=none', function (): void {
    $registered = app(ClientRegistry::class)->register(new NewClient(
        'RP',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ));

    $ticket = app(LoginTickets::class)->mint($this->key, $this->subject->id, ['pwd']);

    $location = $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $registered->client->client_id,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid',
        'state' => 'st',
        'prompt' => 'none',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
        'login_ticket' => $ticket,
    ]))->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $params);

    expect($params['error'])->toBe('login_required')
        ->and(session()->get(PlatformAuth::SESSION_KEY))->toBeNull();
});

/**
 * OFF UNLESS SOMEBODY SAID OTHERWISE — and that has to cover the endpoint where an
 * anonymous caller offers a password, not only the two public documents.
 *
 * The framework gates `/config` and `/session` on this flag. These three were registered
 * unconditionally, so on an install that had never turned the channel on, the embedded
 * password door was live the moment anyone minted a key — and switching the flag off during
 * an incident closed the harmless half and left this serving.
 *
 * The application is rebuilt with the variable off, because the route group is decided at
 * boot: setting config alone would assert against routes that were already registered.
 */
it('serves no sign-in endpoint at all when the channel is switched off', function (): void {
    putenv('CBOX_ID_FRONTEND_API=false');
    $_SERVER['CBOX_ID_FRONTEND_API'] = $_ENV['CBOX_ID_FRONTEND_API'] = 'false';

    $this->refreshApplication();

    try {
        foreach (['/frontend/v1/sign-in', '/frontend/v1/sign-in/factor', '/frontend/v1/sign-in/passkey'] as $route) {
            $this->postJson($route)->assertNotFound();
        }
    } finally {
        putenv('CBOX_ID_FRONTEND_API=true');
        $_SERVER['CBOX_ID_FRONTEND_API'] = $_ENV['CBOX_ID_FRONTEND_API'] = 'true';
    }
});
