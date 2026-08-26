<?php

declare(strict_types=1);

use App\Platform\Install\Contracts\SetupTokens;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * The first-run screen — the lazy path, made safe.
 *
 * The setup token lives on the local disk, so the disk is faked: a real run would write
 * the repository's `storage/app/private`, and two tests sharing one token file would be
 * testing each other.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

it('serves the first-run screen while the platform is empty', function (): void {
    $this->get('/first-run')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/first-run'));
});

it('publishes the setup token to the server, and never to the page', function (): void {
    $this->get('/first-run')->assertOk();

    $token = app(SetupTokens::class)->current();

    expect($token)->not->toBeNull()
        ->and($token)->toHaveLength(64);

    /*
     * The whole point of the token is that reaching the page is not enough to have it.
     * Asserted over the whole DOCUMENT rather than over the props: on a ported page the
     * props are serialised into it, so the document is the superset — a value absent from
     * the body is absent from both.
     */
    $this->get('/first-run')->assertDontSee((string) $token);
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
    // The token file is gone (spent, or never written because the disk is read-only).
    app(SetupTokens::class)->forget();

    claimDeployment(['token' => ''])->assertSessionHasErrors('token');

    expect(app(PlatformOperators::class)->exists())->toBeFalse();
});

it('installs the platform, spends the token, and hands over to the sign-in door', function (): void {
    $token = app(SetupTokens::class)->issue();

    // Handed on to a real door either way: straight into the console when the credential
    // it just created authenticates, and to the sign-in page when it cannot yet. What it
    // must never do is mint a session out of the setup token.
    claimDeployment(['token' => $token])->assertRedirect();

    expect(app(PlatformOperators::class)->findByEmail('root@acme.example'))->not->toBeNull()
        ->and(Environment::query()->where('is_default', true)->count())->toBe(1)
        // Spent: a token left on disk is a live secret for a door that no longer exists.
        ->and(app(SetupTokens::class)->current())->toBeNull();

    // …and the door is gone for good.
    $this->get('/first-run')->assertNotFound();
});

it('refuses to claim a platform that was installed while the form was open', function (): void {
    $token = app(SetupTokens::class)->issue();

    $this->get('/first-run')->assertOk();

    // Someone else claimed it between the render and the submit. The emptiness check is
    // re-asked on the WRITE, not inherited from the render — which is the whole point:
    // the render happened on another request, and everything this endpoint may do rests
    // on the platform still being empty.
    app(PlatformOperators::class)->create('faster@acme.example', 'a-strong-unbreached-passphrase', 'Faster');

    claimDeployment(['token' => $token])->assertNotFound();

    expect(app(PlatformOperators::class)->findByEmail('root@acme.example'))->toBeNull();
});
