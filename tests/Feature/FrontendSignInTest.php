<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\FrontendApi\LoginTicket;
use App\Platform\FrontendApi\LoginTickets;
use App\Platform\FrontendApi\SignInWithTicket;
use App\Platform\PlatformAuth;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\FrontendApiServiceProvider;
use Cbox\Id\Identity\Contracts\Subjects;
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
