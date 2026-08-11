<?php

declare(strict_types=1);

use App\Platform\FrontendApi\PasskeyChallenges;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\FrontendApiServiceProvider;
use Cbox\Id\Identity\Contracts\RelyingParties;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->app['config']->set('cbox-id.frontend_api.enabled', true);
    (new FrontendApiServiceProvider($this->app))->boot();

    $this->key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://app.acme.test']);
});

function asPage(): array
{
    return [
        'X-Cbox-Publishable-Key' => test()->key->key,
        'Origin' => 'https://app.acme.test',
    ];
}

it('hands out a challenge with a handle to carry it', function (): void {
    $body = $this->withHeaders(asPage())
        ->postJson('/frontend/v1/sign-in/passkey/options')
        ->assertOk()
        ->json();

    expect($body)->toHaveKeys(['challenge', 'challenge_token', 'rpId'])
        // Discoverable credentials: the page never says who is signing in, so there is no
        // list to hand back — asking for an email first would be the enumeration oracle in
        // a new costume.
        ->and($body['allowCredentials'])->toBe([])
        ->and($body['userVerification'])->toBe('required');
});

/**
 * A replayed challenge is a replayed assertion. `pull()` reads and forgets in one
 * operation, so two requests racing the same handle cannot both receive it.
 */
it('gives up a challenge exactly once', function (): void {
    $challenges = app(PasskeyChallenges::class);
    $handle = $challenges->issue($this->key, 'a-random-challenge');

    expect($challenges->claim($this->key, $handle))->toBe('a-random-challenge')
        ->and($challenges->claim($this->key, $handle))->toBeNull();
});

/**
 * Both pages may hold valid keys against this environment; the environment alone is not a
 * boundary between two customers' sites.
 */
it('refuses a handle issued to a different key', function (): void {
    $other = app(PublishableKeys::class)->issue('Other', KeyMode::Test, ['https://other.test']);

    $handle = app(PasskeyChallenges::class)->issue($other, 'a-random-challenge');

    expect(app(PasskeyChallenges::class)->claim($this->key, $handle))->toBeNull();
});

it('refuses an assertion with no handle, and one with a handle nobody issued', function (array $body): void {
    $this->withHeaders(asPage())
        ->postJson('/frontend/v1/sign-in/passkey', $body)
        ->assertStatus(401);
})->with([
    'nothing' => [[]],
    'no handle' => [['id' => 'credential-id']],
    'unknown handle' => [['id' => 'credential-id', 'challenge_token' => 'never-issued']],
]);

it('refuses the whole flow to a caller with no key', function (): void {
    $this->postJson('/frontend/v1/sign-in/passkey/options')->assertStatus(401);
    $this->postJson('/frontend/v1/sign-in/passkey', ['id' => 'x', 'challenge_token' => 'y'])->assertStatus(401);
});

/**
 * The same relying party the hosted flow uses. A passkey registered on one rpId cannot
 * answer a challenge on another, so computing it twice in two ways is how an embedded
 * button quietly stops recognising credentials the hosted page created.
 */
it('names the same relying party the hosted flow does', function (): void {
    $embedded = $this->withHeaders(asPage())
        ->postJson('/frontend/v1/sign-in/passkey/options')->json('rpId');

    expect($embedded)->toBe(app(RelyingParties::class)->current()->id);
});
