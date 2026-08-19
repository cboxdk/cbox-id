<?php

declare(strict_types=1);

use App\Platform\FrontendApi\LoginTicket;
use App\Platform\FrontendApi\LoginTickets;
use App\Platform\FrontendApi\PasskeyChallenges;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\FrontendApiServiceProvider;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\RelyingParties;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Enums\AuthMethod;
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
 * A VERIFYING ASSERTION, all the way to a ticket.
 *
 * Every other test in this file stops at a refusal — and a refusal is also what a
 * controller that never verified anything returns. Nothing here could tell the difference
 * between a working passkey door and a dead one, which is how the SSO mandate below went
 * missing from it without a test turning red.
 */
it('mints a ticket for an assertion that verifies', function (): void {
    [$subject] = accountWithOrg('pk-embedded@acme.test');
    fakePasskeys($subject->id);

    $handle = app(PasskeyChallenges::class)->issue($this->key, 'a-random-challenge');

    $body = $this->withHeaders(asPage())->postJson('/frontend/v1/sign-in/passkey', [
        'id' => 'credential-id',
        'challenge_token' => $handle,
    ])->assertOk()->json();

    expect($body['status'])->toBe('ok')
        ->and($body['login_ticket'])->toBeString();

    $ticket = app(LoginTickets::class)->redeem($body['login_ticket'], app(EnvironmentContext::class)->requireEnvironment()->environmentKey());

    expect($ticket?->subject_id)->toBe($subject->id)
        // A passkey IS the factor: no `pwd` nobody typed, and the vocabulary is the one
        // the hosted door uses — an RP gating on `amr` must not see two answers for one
        // credential.
        ->and($ticket?->amr)->toBe(AuthMethod::forPasskey());
});

/**
 * THE MANDATE APPLIES TO THIS DOOR TOO.
 *
 * An organization told by the console that its identity provider is the only way in was
 * open to anybody holding a passkey: the hosted door asks `localSignInAllowedFor()` and
 * this one did not, so the embedded button was a second entrance into an organization
 * that had been shown a page saying it had closed them.
 */
it('refuses a passkey in an organization that mandates SSO', function (): void {
    [$subject, $organization] = accountWithOrg('pk-sso@acme.test');
    fakePasskeys($subject->id);

    app(AuthPolicies::class)->setForOrganization($organization->id, new AuthPolicy(sso: SsoEnforcement::Required));

    $handle = app(PasskeyChallenges::class)->issue($this->key, 'a-random-challenge');

    $this->withHeaders(asPage())->postJson('/frontend/v1/sign-in/passkey', [
        'id' => 'credential-id',
        'challenge_token' => $handle,
    ])->assertStatus(403)->assertJsonPath('status', 'sso_required');

    // And nothing was minted to be redeemed later.
    expect(LoginTicket::query()->count())->toBe(0);
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
