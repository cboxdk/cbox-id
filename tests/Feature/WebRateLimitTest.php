<?php

declare(strict_types=1);

use App\Http\WebRateLimiters;
use App\Platform\PlatformAuth;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\FrontendApiServiceProvider;
use Cbox\Id\Identity\Contracts\SessionManager;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * THE BROWSER DOORS THAT TAKE A SECRET FROM THE URL, OR HAND A CHALLENGE TO ANYONE, ARE
 * METERED.
 *
 * Every door below answered as fast as the database could, for as long as a caller cared
 * to ask. Each test spends the door's whole budget and then asks once more — with the
 * throttle removed from the route, that last request is answered like all the others and
 * the test fails on the 429 it never got.
 */
beforeEach(function (): void {
    installedDeployment();
});

it('registers the named limiters the browser doors use', function (): void {
    // An unregistered name is parsed as a NUMERIC limit of zero — every mailed link would
    // answer 429 on its first press, which is a worse outage than the one this prevents.
    foreach (['link-token', 'passkey', 'magic-link-send', 'verification-resend', 'first-run'] as $limiter) {
        expect(RateLimiter::limiter($limiter))->not->toBeNull("The `{$limiter}` limiter is not registered.");
    }
});

it('throttles pressing one sign-in link over and over', function (): void {
    foreach (range(1, WebRateLimiters::LINK_PER_TOKEN) as $ignored) {
        $this->post(route('magic.redeem.store', 'not-a-live-token'))->assertRedirect();
    }

    $this->post(route('magic.redeem.store', 'not-a-live-token'))->assertStatus(429);
});

/**
 * The per-token bucket alone would be useless against the only attack that matters: a
 * script iterating over made-up tokens gets a fresh one per guess. The per-address limit
 * is what binds it.
 */
it('throttles guessing at many sign-in links from one address', function (): void {
    foreach (range(1, WebRateLimiters::LINK_PER_IP) as $guess) {
        $this->post(route('magic.redeem.store', 'guess-'.$guess))->assertRedirect();
    }

    $this->post(route('magic.redeem.store', 'one-guess-too-many'))->assertStatus(429);
});

it('gives each kind of link its own budget', function (): void {
    foreach (range(1, WebRateLimiters::LINK_PER_IP) as $guess) {
        $this->post(route('magic.redeem.store', 'guess-'.$guess))->assertRedirect();
    }

    // The sign-in link door is spent; the email confirmation door is a different route
    // and must not inherit that.
    $this->post(route('verification.verify.store', 'some-token'))->assertRedirect();
});

it('throttles the Admin Portal setup link', function (): void {
    foreach (range(1, WebRateLimiters::LINK_PER_TOKEN) as $ignored) {
        $this->post(route('portal.enter.store', 'not-a-live-token'))->assertRedirect(route('portal.expired'));
    }

    $this->post(route('portal.enter.store', 'not-a-live-token'))->assertStatus(429);
});

it('throttles the email confirmation link', function (): void {
    foreach (range(1, WebRateLimiters::LINK_PER_TOKEN) as $ignored) {
        $this->post(route('verification.verify.store', 'not-a-live-token'))->assertRedirect();
    }

    $this->post(route('verification.verify.store', 'not-a-live-token'))->assertStatus(429);
});

it('throttles the team invitation link on both the page and the accept', function (): void {
    $page = URL::signedRoute('organization.invite.accept', ['token' => 'not-a-live-token']);

    // The page looks the token up to say who is inviting whom, so it is metered as well.
    foreach (range(1, WebRateLimiters::LINK_PER_TOKEN) as $ignored) {
        $this->get($page)->assertRedirect(route('login'));
    }

    $this->get($page)->assertStatus(429);

    $accept = URL::signedRoute('organization.invite.accept.store', ['token' => 'another-dead-token']);

    foreach (range(1, WebRateLimiters::LINK_PER_TOKEN) as $ignored) {
        expect($this->post($accept)->status())->not->toBe(429);
    }

    $this->post($accept)->assertStatus(429);
});

it('throttles minting passkey sign-in challenges', function (): void {
    foreach (range(1, WebRateLimiters::PASSKEY_PER_IP) as $ignored) {
        $this->postJson(route('passkeys.login.options'))->assertOk();
    }

    $this->postJson(route('passkeys.login.options'))->assertStatus(429)->assertHeader('Retry-After');
});

it('throttles minting passkey enrolment challenges', function (): void {
    [$subject] = accountWithOrg('pk-throttle@acme.test');
    $this->withSession([
        PlatformAuth::SESSION_KEY => app(SessionManager::class)->start($subject->id, null, ['pwd'])->id,
        'cbox.sudo_confirmed_at' => time(),
    ]);

    foreach (range(1, WebRateLimiters::PASSKEY_PER_IP) as $ignored) {
        $this->postJson(route('passkeys.register.options'))->assertOk();
    }

    $this->postJson(route('passkeys.register.options'))->assertStatus(429)->assertHeader('Retry-After');
});

it('throttles the Frontend API passkey ceremony per address, under the per-key ceiling', function (): void {
    $this->app['config']->set('cbox-id.frontend_api.enabled', true);
    (new FrontendApiServiceProvider($this->app))->boot();

    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://app.acme.test']);
    $page = ['X-Cbox-Publishable-Key' => $key->key, 'Origin' => 'https://app.acme.test'];

    // Preflights are free: a browser sends one ahead of every real request, and charging
    // for it would halve the budget of exactly the callers this is not aimed at.
    foreach (range(1, 5) as $ignored) {
        $this->withHeaders(['Origin' => 'https://app.acme.test'])
            ->options('/frontend/v1/sign-in/passkey/options')
            ->assertNoContent();
    }

    foreach (range(1, WebRateLimiters::PASSKEY_PER_IP) as $ignored) {
        $this->withHeaders($page)->postJson('/frontend/v1/sign-in/passkey/options')->assertOk();
    }

    $this->withHeaders($page)->postJson('/frontend/v1/sign-in/passkey/options')->assertStatus(429);

    // The assertion half is its own route and its own bucket, and it is metered too.
    foreach (range(1, WebRateLimiters::PASSKEY_PER_IP) as $ignored) {
        expect($this->withHeaders($page)->postJson('/frontend/v1/sign-in/passkey')->status())->not->toBe(429);
    }

    $this->withHeaders($page)->postJson('/frontend/v1/sign-in/passkey')->assertStatus(429)->assertHeader('Retry-After');
});

/*
|--------------------------------------------------------------------------
| The doors that send mail, accept an invitation or claim the deployment
|--------------------------------------------------------------------------
|
| Each already answers its own friendly "try again shortly" from inside — the sentence a
| person reads. The named limiter is the ceiling in front of it, for the script that does
| not read: a 429 with Retry-After, the answer a client backs off on.
*/

it('throttles asking for sign-in links for one address, then for any address', function (): void {
    foreach (range(1, WebRateLimiters::MAGIC_LINK_PER_EMAIL) as $ignored) {
        expect($this->post(route('login.magic-link'), ['email' => 'dana@acme.test'])->status())->not->toBe(429);
    }

    $this->post(route('login.magic-link'), ['email' => 'dana@acme.test'])->assertStatus(429)->assertHeader('Retry-After');

    // Cycling the address typed in buys nothing past the per-address ceiling.
    foreach (range(1, WebRateLimiters::MAGIC_LINK_PER_IP - WebRateLimiters::MAGIC_LINK_PER_EMAIL) as $n) {
        expect($this->post(route('login.magic-link'), ['email' => "guess-{$n}@acme.test"])->status())->not->toBe(429);
    }

    $this->post(route('login.magic-link'), ['email' => 'one-more@acme.test'])->assertStatus(429)->assertHeader('Retry-After');
});

it('throttles pressing an environment invitation, on the page and the accept', function (): void {
    foreach (range(1, WebRateLimiters::LINK_PER_TOKEN) as $ignored) {
        expect($this->get(route('invitation.accept', 'not-a-live-token'))->status())->not->toBe(429);
    }

    $this->get(route('invitation.accept', 'not-a-live-token'))->assertStatus(429)->assertHeader('Retry-After');

    foreach (range(1, WebRateLimiters::LINK_PER_TOKEN) as $ignored) {
        expect($this->post(route('invitation.accept.store', 'another-dead-token'))->status())->not->toBe(429);
    }

    $this->post(route('invitation.accept.store', 'another-dead-token'))->assertStatus(429)->assertHeader('Retry-After');
});
