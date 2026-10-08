<?php

declare(strict_types=1);

use App\Http\WebRateLimiters;
use App\Platform\Install\Contracts\SetupTokens;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * The first-run screen — the lazy path, made safe.
 *
 * The setup token lives in the database every replica shares, hashed, so these tests need
 * no faked disk — and the multi-replica ones below prove a replica's own disk plays no part.
 */
it('serves the first-run screen while the platform is empty', function (): void {
    $this->get('/first-run')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/first-run'));
});

it('arms a setup token on the first look, and never shows it on the page', function (): void {
    expect(app(SetupTokens::class)->armed())->toBeFalse();

    $this->get('/first-run')->assertOk();

    expect(app(SetupTokens::class)->armed())->toBeTrue();

    // Only a hash is stored: the database holds nothing that claims the platform.
    $hash = DB::table('setup_tokens')->value('token_hash');

    expect($hash)->toBeString()->toHaveLength(64);

    /*
     * The whole point of the token is that reaching the page is not enough to have it.
     * Asserted over the whole DOCUMENT rather than over the props: on a ported page the
     * props are serialised into it, so the document is the superset — a value absent from
     * the body is absent from both. The value printed by the command is checked the same
     * way: neither it nor its stored hash reaches the page.
     */
    $token = app(SetupTokens::class)->rotate();

    $this->get('/first-run')->assertDontSee($token)->assertDontSee((string) DB::table('setup_tokens')->value('token_hash'));
});

it('keeps the token the operator holds when the page is looked at again', function (): void {
    $token = app(SetupTokens::class)->rotate();

    // A second visitor — or a second replica serving its first look — must not re-arm over
    // the value the operator is already holding.
    $this->get('/first-run')->assertOk();
    $this->get('/first-run')->assertOk();

    expect(app(SetupTokens::class)->matches($token))->toBeTrue();
});

it('404s once anything has claimed the platform', function (): void {
    app(PlatformOperators::class)->create('root@acme.example', 'a-strong-unbreached-passphrase', 'Root');

    $this->get('/first-run')->assertNotFound();
});

it('points every other web route at the first-run screen while empty', function (): void {
    $this->get('/login')->assertRedirect(route('first-run'));
    $this->get('/')->assertRedirect(route('first-run'));
});

it('stops pointing at the first-run screen once the platform is installed', function (): void {
    app(PlatformOperators::class)->create('root@acme.example', 'a-strong-unbreached-passphrase', 'Root');

    $this->get('/login')->assertOk();
});

it('leaves back-channel and machine surfaces alone while empty', function (): void {
    // A 302 to a setup page is not an answer any of these callers can read, and the
    // JSON ones would be handed HTML.
    $this->getJson('/.well-known/openid-configuration')->assertStatus(200);
    $this->get('/up')->assertStatus(200);
});

it('refuses a wrong setup token, and provisions nothing', function (): void {
    claimDeployment(['token' => str_repeat('a', 64)])->assertSessionHasErrors('token');

    expect(app(PlatformOperators::class)->exists())->toBeFalse()
        ->and(Environment::query()->count())->toBe(0);
});

it('refuses an absent setup token — no token issued is not a wildcard', function (): void {
    // No token was ever armed, or it was spent.
    app(SetupTokens::class)->forget();

    claimDeployment(['token' => ''])->assertSessionHasErrors('token');

    expect(app(PlatformOperators::class)->exists())->toBeFalse();
});

it('installs the platform, spends the token, and hands over to the sign-in door', function (): void {
    $token = app(SetupTokens::class)->rotate();

    // Handed on to a real door either way: straight into the console when the credential
    // it just created authenticates, and to the sign-in page when it cannot yet. What it
    // must never do is mint a session out of the setup token.
    claimDeployment(['token' => $token])->assertRedirect();

    expect(app(PlatformOperators::class)->findByEmail('root@acme.example'))->not->toBeNull()
        ->and(Environment::query()->where('is_default', true)->count())->toBe(1)
        // Spent: a token left behind is a live secret for a door that no longer exists.
        ->and(app(SetupTokens::class)->armed())->toBeFalse()
        ->and(DB::table('setup_tokens')->count())->toBe(0);

    // …and the door is gone for good.
    $this->get('/first-run')->assertNotFound();
});

it('refuses to claim a platform that was installed while the form was open', function (): void {
    $token = app(SetupTokens::class)->rotate();

    $this->get('/first-run')->assertOk();

    // Someone else claimed it between the render and the submit. The emptiness check is
    // re-asked on the WRITE, not inherited from the render — which is the whole point:
    // the render happened on another request, and everything this endpoint may do rests
    // on the platform still being empty.
    app(PlatformOperators::class)->create('faster@acme.example', 'a-strong-unbreached-passphrase', 'Faster');

    claimDeployment(['token' => $token])->assertNotFound();

    expect(app(PlatformOperators::class)->findByEmail('root@acme.example'))->toBeNull();
});

/**
 * The setup token is the whole credential for an unclaimed deployment. The controller locks a
 * guesser out with a sentence after a few wrong tokens; the named limiter in front of it is
 * the ceiling for a script that ignores the sentence: a 429 with Retry-After.
 */
it('throttles claiming the deployment, whatever token is guessed', function (): void {
    foreach (range(1, WebRateLimiters::FIRST_RUN_PER_IP) as $guess) {
        expect(claimDeployment(['token' => 'guess-'.$guess])->status())->not->toBe(429);
    }

    claimDeployment(['token' => 'one-guess-too-many'])->assertStatus(429)->assertHeader('Retry-After');
})->group('security');
