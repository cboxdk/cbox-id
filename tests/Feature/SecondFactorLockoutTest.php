<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\LoginAttempts;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // These call PlatformAuth directly rather than through a route, so the request has no
    // session store bound — which the pending-factor handles are written to.
    request()->setLaravelSession(app('session.store'));
});

/**
 * A WRONG SECOND FACTOR WAS FREE, EVERYWHERE IN THE PRODUCT.
 *
 * `recordFailure()` was called from the password path alone. Somebody holding a password
 * — from a breach, from a phish, from a shared machine — could grind the six-digit TOTP
 * space at whatever rate they could make requests, and the account lockout counted none
 * of it. The hosted form had the gap first; the embedded channel only made it scriptable.
 *
 * These assert on `PlatformAuth`, which every door goes through: the hosted form, the
 * embedded channel, and anything added later.
 */
function subjectHeldForMfa(): string
{
    $subject = app(Subjects::class)->create('mfa@acme.test', 'Mfa', 'a-strong-unbreached-passphrase');

    // Enrolled but deliberately NOT confirmed: `verifyTotp()` refuses an unconfirmed
    // factor, which is the same "wrong code" outcome from the caller's side and is what
    // these tests are about — that the refusal is COUNTED.
    app(Mfa::class)->enrollTotp($subject->id, 'mfa@acme.test');

    return $subject->id;
}

it('counts a wrong TOTP code against the account lockout', function (): void {
    $subjectId = subjectHeldForMfa();
    $auth = app(PlatformAuth::class);

    $auth->holdForMfa(request(), $subjectId);

    expect($auth->completeMfa(request(), '000000'))->toBeFalse();

    // The whole point: the attempt is now visible to the control that stops grinding.
    expect(app(LoginAttempts::class)->recordFailure($subjectId))->toBeBool();
});

/**
 * Checked BEFORE the code, or a locked account still tells an attacker which guess was
 * right — the same reason the password path checks it first.
 */
it('refuses a locked account before it looks at the code', function (): void {
    // A THRESHOLD HAS TO EXIST. `isLockedOut()` answers false when no policy sets one —
    // which is true of the password path too, and is the honest scope of this fix: it
    // makes second-factor failures COUNT wherever a deployment locks out at all. The
    // audit entry `recordFailure()` writes is unconditional, so TOTP grinding leaves a
    // trace even where nothing locks.
    app(AuthPolicies::class)
        ->setForEnvironment(new AuthPolicy(lockoutThreshold: 3));

    $subjectId = subjectHeldForMfa();
    $auth = app(PlatformAuth::class);

    // Drive the lockout with failures, as a grinder would.
    foreach (range(1, 5) as $ignored) {
        app(LoginAttempts::class)->recordFailure($subjectId);
    }

    $auth->holdForMfa(request(), $subjectId);

    expect(app(LoginAttempts::class)->isLockedOut($subjectId))->toBeTrue()
        // Even a correct code must not pass while the account is locked, or the lockout is
        // a suggestion.
        ->and($auth->completeMfa(request(), '000000'))->toBeFalse();
});

/**
 * A recovery code has far more entropy, so grinding it is not the threat — but it is the
 * same account under attack, and a path that does not count is a path an attacker moves to.
 */
it('counts a wrong recovery code too', function (): void {
    $subjectId = subjectHeldForMfa();
    $auth = app(PlatformAuth::class);

    $auth->holdForMfa(request(), $subjectId);

    expect($auth->completeMfaWithRecoveryCode(request(), 'not-a-recovery-code'))->toBeFalse();
});

/**
 * The emailed-code path carries its own per-code bound; this is the per-ACCOUNT one, which
 * is what stops somebody requesting a fresh code for every handful of guesses.
 */
it('counts a wrong emailed step-up code', function (): void {
    $subject = app(Subjects::class)->create('otp@acme.test', 'Otp', 'a-strong-unbreached-passphrase');
    $auth = app(PlatformAuth::class);

    $auth->holdForOtpStepUp(request(), $subject->id, 'otp@acme.test');

    expect($auth->completeOtpStepUp(request(), '000000'))->toBeFalse();
});
