<?php

declare(strict_types=1);

use App\Platform\FrontendApi\LoginTicket;
use App\Platform\FrontendApi\LoginTickets;
use App\Platform\FrontendApi\SignInWithTicket;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\FrontendApiServiceProvider;
use Cbox\Id\FrontendApi\Models\PublishableKey;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->app['config']->set('cbox-id.frontend_api.enabled', true);
    (new FrontendApiServiceProvider($this->app))->boot();

    $this->key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://app.acme.test']);
    $this->subject = app(Subjects::class)->create('mfa@acme.test', 'Mfa', 'a-strong-unbreached-passphrase');
});

/** The headers a page on an allow-listed origin sends. */
function embedded(?PublishableKey $key = null): array
{
    return [
        'X-Cbox-Publishable-Key' => ($key ?? test()->key)->key,
        'Origin' => 'https://app.acme.test',
    ];
}

/** Enrol and confirm a real TOTP factor, and hand back its secret. */
function confirmedTotpSecret(string $subjectId, string $email): string
{
    $enrollment = app(Mfa::class)->enrollTotp($subjectId, $email);

    // A code from the previous step, so the one this returns for "now" is still unused —
    // TOTP refuses a code it has already seen.
    app(Mfa::class)->confirmTotp($subjectId, app(TotpAuthenticator::class)->codeAt($enrollment->secret, time() - 30));

    return $enrollment->secret;
}

/**
 * THE BYPASS THIS WHOLE STAGE EXISTS TO PREVENT. A ticket that still owes a second factor
 * is not a sign-in, and redeeming one at /authorize would turn "password correct, MFA
 * pending" into a session — which is precisely the state MFA is there to refuse.
 */
it('never redeems a pending ticket as a sign-in', function (): void {
    $pending = app(LoginTickets::class)
        ->mintPending($this->key, $this->subject->id, ['pwd'], 'pending_mfa');

    expect(app(LoginTickets::class)->redeem($pending, app(EnvironmentContext::class)->requireEnvironment()->environmentKey()))->toBeNull();
});

/**
 * THE WHOLE SECOND HALF, OVER HTTP, ending in a session.
 *
 * Everything else in this file asserts a refusal — and a refusal is also the answer to a
 * request that never reached the code at all. Without one test that gets all the way
 * through, `SecondFactorController`'s success branch and `SignInController::pending()`
 * could both be dead and nothing here would say so: a two-step embedded sign-in nobody can
 * finish, green.
 */
it('carries a password and a real TOTP code through to a session', function (): void {
    $secret = confirmedTotpSecret($this->subject->id, 'mfa@acme.test');

    $first = $this->withHeaders(embedded())->postJson('/frontend/v1/sign-in', [
        'email' => 'mfa@acme.test',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertOk();

    expect($first->json('status'))->toBe('mfa_required')
        // NOTHING about who is being challenged crosses this channel — the pending subject
        // stays in the session `PlatformAuth` put it in.
        ->and($first->json())->not->toHaveKey('subject_id')
        ->and($first->json())->not->toHaveKey('email');

    $second = $this->withHeaders(embedded())->postJson('/frontend/v1/sign-in/factor', [
        'mfa_token' => $first->json('mfa_token'),
        'code' => app(TotpAuthenticator::class)->codeAt($secret, time()),
    ])->assertOk();

    expect($second->json('status'))->toBe('ok');

    // And the ticket it hands back is a real sign-in: it establishes, and the `amr` names
    // both factors rather than only the password.
    $ticket = app(LoginTickets::class)->redeem($second->json('login_ticket'), app(EnvironmentContext::class)->requireEnvironment()->environmentKey());

    expect($ticket?->subject_id)->toBe($this->subject->id)
        ->and($ticket?->amr)->toContain('pwd')
        ->and($ticket?->amr)->toContain('mfa')
        ->and($ticket?->amr)->toContain('otp');
});

it('refuses a wrong code and does not hand back a ticket', function (): void {
    confirmedTotpSecret($this->subject->id, 'mfa@acme.test');

    $first = $this->withHeaders(embedded())->postJson('/frontend/v1/sign-in', [
        'email' => 'mfa@acme.test',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertOk();

    $this->withHeaders(embedded())->postJson('/frontend/v1/sign-in/factor', [
        'mfa_token' => $first->json('mfa_token'),
        'code' => '000000',
    ])->assertStatus(401)->assertJsonMissing(['login_ticket']);
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
        expect(app(LoginTickets::class)->claimAttempt($pending, 'pending_mfa', $this->key))->not->toBeNull();
    }

    // The bound is what stops the ticket becoming something to brute-force a six-digit
    // space against.
    expect(app(LoginTickets::class)->claimAttempt($pending, 'pending_mfa', $this->key))->toBeNull();
});

/**
 * Counted BEFORE the code is checked, so a caller that crashes the verifier or walks away
 * still spends one — otherwise the bound is not a bound.
 */
it('counts the attempt even when nothing checks a code', function (): void {
    $pending = app(LoginTickets::class)
        ->mintPending($this->key, $this->subject->id, ['pwd'], 'pending_mfa');

    app(LoginTickets::class)->claimAttempt($pending, 'pending_mfa', $this->key);

    expect(LoginTicket::query()->first()?->attempts)->toBe(1);
});

it('refuses a token presented for the wrong stage', function (): void {
    $pending = app(LoginTickets::class)
        ->mintPending($this->key, $this->subject->id, ['pwd'], 'pending_mfa');

    // An emailed step-up code offered against a TOTP challenge is not the same challenge.
    expect(app(LoginTickets::class)->claimAttempt($pending, 'pending_otp', $this->key))->toBeNull();
});

/**
 * Promotion replaces the row rather than minting beside it: a pending ticket that survived
 * its own promotion would be a second chance at a factor already used.
 */
it('promotes the same row and leaves nothing behind to reuse', function (): void {
    $pending = app(LoginTickets::class)
        ->mintPending($this->key, $this->subject->id, ['pwd'], 'pending_mfa');

    $ticket = app(LoginTickets::class)->claimAttempt($pending, 'pending_mfa', $this->key);
    $ready = app(LoginTickets::class)->promote($ticket, ['pwd', 'mfa']);

    expect(LoginTicket::query()->count())->toBe(1)
        // The old token is dead the moment the new one exists.
        ->and(app(LoginTickets::class)->claimAttempt($pending, 'pending_mfa', $this->key))->toBeNull()
        ->and(app(LoginTickets::class)->redeem($ready, app(EnvironmentContext::class)->requireEnvironment()->environmentKey())?->amr)->toBe(['pwd', 'mfa']);
});

it('refuses the second factor without a key, a token or a code', function (array $body): void {
    $this->withHeaders(embedded())
        ->postJson('/frontend/v1/sign-in/factor', $body)
        ->assertStatus(401);
})->with([
    'no token' => [['code' => '123456']],
    'no code' => [['mfa_token' => 'nonsense']],
    'neither' => [[]],
]);

/**
 * A token minted by one customer's page must not be completable from another's, even
 * though both hold valid keys against this environment.
 *
 * ASSERTING THE 401 ALONE PROVED NOTHING — it is also the answer to the bogus code the
 * request carries, so the check could be deleted outright and this stayed green. What the
 * ownership binding actually buys is below it: the wrong page must not be able to spend
 * the real person's attempts.
 */
it('refuses a token belonging to a different publishable key', function (): void {
    $other = app(PublishableKeys::class)->issue('Other', KeyMode::Test, ['https://other.acme.test']);

    $pending = app(LoginTickets::class)
        ->mintPending($other, $this->subject->id, ['pwd'], 'pending_mfa');

    $this->withHeaders(embedded())->postJson('/frontend/v1/sign-in/factor', [
        'mfa_token' => $pending,
        'code' => '123456',
    ])->assertStatus(401);
});

it('does not let another key burn the attempts on somebody else\'s ticket', function (): void {
    $other = app(PublishableKeys::class)->issue('Other', KeyMode::Test, ['https://other.acme.test']);

    $pending = app(LoginTickets::class)
        ->mintPending($other, $this->subject->id, ['pwd'], 'pending_mfa');

    // Five tries through the wrong key — the whole budget, if the count came first.
    foreach (range(1, 5) as $ignored) {
        $this->withHeaders(embedded())->postJson('/frontend/v1/sign-in/factor', [
            'mfa_token' => $pending,
            'code' => '123456',
        ])->assertStatus(401);
    }

    expect(LoginTicket::query()->first()?->attempts)->toBe(0)
        // And the person the ticket belongs to can still finish.
        ->and(app(LoginTickets::class)->claimAttempt($pending, 'pending_mfa', $other))->not->toBeNull();
});

/**
 * ONE WRONG CODE COSTS ONE ATTEMPT.
 *
 * Trying TOTP and then the recovery code over the same input recorded a failure for each,
 * so a mistyped code charged the account lockout twice — halving the real threshold for a
 * person and doubling an attacker's rate of locking somebody else out.
 */
it('charges the lockout once for one mistyped code', function (): void {
    confirmedTotpSecret($this->subject->id, 'mfa@acme.test');

    $first = $this->withHeaders(embedded())->postJson('/frontend/v1/sign-in', [
        'email' => 'mfa@acme.test',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertOk();

    $this->withHeaders(embedded())->postJson('/frontend/v1/sign-in/factor', [
        'mfa_token' => $first->json('mfa_token'),
        'code' => '000000',
    ])->assertStatus(401);

    // Asserted on the audit entry rather than the counter: `recordFailure()` writes
    // `user.sign_in_failed` on every failure whether or not a lockout threshold is
    // configured, so this is the one observation that holds on any deployment.
    expect(DB::table('audit_logs')
        ->where('action', 'user.sign_in_failed')
        ->where('target_id', $this->subject->id)
        ->count())->toBe(1);
});

/**
 * The ticket is not the session. Nothing the second factor hands back may be usable until
 * `/authorize` redeems it, and it may be redeemed exactly once.
 */
it('spends the promoted ticket on its first redemption and no more', function (): void {
    $secret = confirmedTotpSecret($this->subject->id, 'mfa@acme.test');

    $first = $this->withHeaders(embedded())->postJson('/frontend/v1/sign-in', [
        'email' => 'mfa@acme.test',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertOk();

    $ready = $this->withHeaders(embedded())->postJson('/frontend/v1/sign-in/factor', [
        'mfa_token' => $first->json('mfa_token'),
        'code' => app(TotpAuthenticator::class)->codeAt($secret, time()),
    ])->assertOk()->json('login_ticket');

    expect(app(SignInWithTicket::class)->establish(request(), $ready))->toBeTrue()
        ->and(app(SignInWithTicket::class)->establish(request(), $ready))->toBeFalse();
});
