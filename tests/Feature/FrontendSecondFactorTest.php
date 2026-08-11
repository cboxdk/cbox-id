<?php

declare(strict_types=1);

use App\Platform\FrontendApi\LoginTicket;
use App\Platform\FrontendApi\LoginTickets;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\FrontendApiServiceProvider;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->app['config']->set('cbox-id.frontend_api.enabled', true);
    (new FrontendApiServiceProvider($this->app))->boot();

    $this->key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://app.acme.test']);
    $this->subject = app(Subjects::class)->create('mfa@acme.test', 'Mfa', 'a-strong-unbreached-passphrase');
});

/**
 * THE BYPASS THIS WHOLE STAGE EXISTS TO PREVENT. A ticket that still owes a second factor
 * is not a sign-in, and redeeming one at /authorize would turn "password correct, MFA
 * pending" into a session — which is precisely the state MFA is there to refuse.
 */
it('never redeems a pending ticket as a sign-in', function (): void {
    $pending = app(LoginTickets::class)
        ->mintPending($this->key, $this->subject->id, ['pwd'], 'pending_mfa');

    expect(app(LoginTickets::class)->redeem($pending))->toBeNull();
});

/**
 * A mistyped six-digit code must not cost somebody their password as well — the hosted
 * form does not do that, and an embedded one being stricter is a worse product for no
 * security.
 */
it('survives a few wrong codes and then stops', function (): void {
    $pending = app(LoginTickets::class)
        ->mintPending($this->key, $this->subject->id, ['pwd'], 'pending_mfa');

    foreach (range(1, 5) as $ignored) {
        expect(app(LoginTickets::class)->claimAttempt($pending, 'pending_mfa'))->not->toBeNull();
    }

    // The bound is what stops the ticket becoming something to brute-force a six-digit
    // space against.
    expect(app(LoginTickets::class)->claimAttempt($pending, 'pending_mfa'))->toBeNull();
});

/**
 * Counted BEFORE the code is checked, so a caller that crashes the verifier or walks away
 * still spends one — otherwise the bound is not a bound.
 */
it('counts the attempt even when nothing checks a code', function (): void {
    $pending = app(LoginTickets::class)
        ->mintPending($this->key, $this->subject->id, ['pwd'], 'pending_mfa');

    app(LoginTickets::class)->claimAttempt($pending, 'pending_mfa');

    expect(LoginTicket::query()->first()?->attempts)->toBe(1);
});

it('refuses a token presented for the wrong stage', function (): void {
    $pending = app(LoginTickets::class)
        ->mintPending($this->key, $this->subject->id, ['pwd'], 'pending_mfa');

    // An emailed step-up code offered against a TOTP challenge is not the same challenge.
    expect(app(LoginTickets::class)->claimAttempt($pending, 'pending_otp'))->toBeNull();
});

/**
 * Promotion replaces the row rather than minting beside it: a pending ticket that survived
 * its own promotion would be a second chance at a factor already used.
 */
it('promotes the same row and leaves nothing behind to reuse', function (): void {
    $pending = app(LoginTickets::class)
        ->mintPending($this->key, $this->subject->id, ['pwd'], 'pending_mfa');

    $ticket = app(LoginTickets::class)->claimAttempt($pending, 'pending_mfa');
    $ready = app(LoginTickets::class)->promote($ticket, ['pwd', 'mfa']);

    expect(LoginTicket::query()->count())->toBe(1)
        // The old token is dead the moment the new one exists.
        ->and(app(LoginTickets::class)->claimAttempt($pending, 'pending_mfa'))->toBeNull()
        ->and(app(LoginTickets::class)->redeem($ready)?->amr)->toBe(['pwd', 'mfa']);
});

it('refuses the second factor without a key, a token or a code', function (array $body): void {
    $this->withHeaders([
        'X-Cbox-Publishable-Key' => $this->key->key,
        'Origin' => 'https://app.acme.test',
    ])->postJson('/frontend/v1/sign-in/factor', $body)->assertStatus(401);
})->with([
    'no token' => [['code' => '123456']],
    'no code' => [['mfa_token' => 'nonsense']],
    'neither' => [[]],
]);

/**
 * A token minted by one customer's page must not be completable from another's, even
 * though both hold valid keys against this environment.
 */
it('refuses a token belonging to a different publishable key', function (): void {
    $other = app(PublishableKeys::class)->issue('Other', KeyMode::Test, ['https://other.acme.test']);

    $pending = app(LoginTickets::class)
        ->mintPending($other, $this->subject->id, ['pwd'], 'pending_mfa');

    $this->withHeaders([
        'X-Cbox-Publishable-Key' => $this->key->key,
        'Origin' => 'https://app.acme.test',
    ])->postJson('/frontend/v1/sign-in/factor', [
        'mfa_token' => $pending,
        'code' => '123456',
    ])->assertStatus(401);
});
