<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\LoginAttempts;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

/**
 * A subject with a factor that genuinely WORKS, and the secret behind it.
 *
 * Needed for the one case that cannot be written with an unconfirmed factor: that a
 * CORRECT code is still refused while the account is locked. Without it "even a correct
 * code must not pass" was a sentence in a comment above an assertion about `'000000'`,
 * which is false either way.
 *
 * @return array{0: string, 1: string}
 */
function subjectWithWorkingTotp(): array
{
    $subject = app(Subjects::class)->create('totp@acme.test', 'Totp', 'a-strong-unbreached-passphrase');

    $enrollment = app(Mfa::class)->enrollTotp($subject->id, 'totp@acme.test');

    // Confirmed with the previous step's code, so the one for "now" is still unused.
    app(Mfa::class)->confirmTotp($subject->id, app(TotpAuthenticator::class)->codeAt($enrollment->secret, time() - 30));

    return [$subject->id, $enrollment->secret];
}

/** Failures recorded against this subject, which is what "counted" means. */
function recordedFailures(string $subjectId): int
{
    return DB::table('audit_logs')
        ->where('action', 'user.sign_in_failed')
        ->where('target_id', $subjectId)
        ->count();
}

it('counts a wrong TOTP code against the account lockout', function (): void {
    $subjectId = subjectHeldForMfa();
    $auth = app(PlatformAuth::class);

    $auth->holdForMfa(request(), $subjectId);

    expect($auth->completeMfa(request(), '000000'))->toBeFalse();

    // THE WHOLE POINT, and it has to be measured on what the code under test did.
    //
    // This used to assert `expect(recordFailure($subjectId))->toBeBool()` — the return
    // TYPE of a method the test itself calls, which is true whether or not `completeMfa()`
    // recorded anything. Deleting both the lockout guard and the `recordFailure()` call —
    // restoring the exact bug this file was written for — left all four tests green and
    // TOTP grinding free again.
    expect(recordedFailures($subjectId))->toBe(1);
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

    [$subjectId, $secret] = subjectWithWorkingTotp();
    $auth = app(PlatformAuth::class);

    // Drive the lockout with failures, as a grinder would.
    foreach (range(1, 5) as $ignored) {
        app(LoginAttempts::class)->recordFailure($subjectId);
    }

    $auth->holdForMfa(request(), $subjectId);

    // A CORRECT code, against a CONFIRMED factor. The previous version passed '000000'
    // against a deliberately unconfirmed factor — false either way — so the comment
    // promised something no assertion made.
    expect(app(LoginAttempts::class)->isLockedOut($subjectId))->toBeTrue()
        ->and($auth->completeMfa(request(), app(TotpAuthenticator::class)->codeAt($secret, time())))->toBeFalse();
});

/**
 * And the refusal must come BEFORE the code is looked at, or a locked account still tells
 * an attacker which guess was right — the timing difference between "checked and wrong"
 * and "not checked at all" is the oracle.
 */
it('does not consume the code it refuses while locked', function (): void {
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(lockoutThreshold: 3));

    [$subjectId, $secret] = subjectWithWorkingTotp();
    $auth = app(PlatformAuth::class);

    foreach (range(1, 5) as $ignored) {
        app(LoginAttempts::class)->recordFailure($subjectId);
    }

    $before = recordedFailures($subjectId);

    $auth->holdForMfa(request(), $subjectId);
    $auth->completeMfa(request(), app(TotpAuthenticator::class)->codeAt($secret, time()));

    // Nothing new recorded: the guard returned before the verifier ran, so the attempt
    // cost nothing and told nobody anything.
    expect(recordedFailures($subjectId))->toBe($before);
});

/**
 * A recovery code has far more entropy, so grinding it is not the threat — but it is the
 * same account under attack, and a path that does not count is a path an attacker moves to.
 */
it('counts a wrong recovery code too', function (): void {
    $subjectId = subjectHeldForMfa();
    $auth = app(PlatformAuth::class);

    $auth->holdForMfa(request(), $subjectId);

    expect($auth->completeMfaWithRecoveryCode(request(), 'not-a-recovery-code'))->toBeFalse()
        ->and(recordedFailures($subjectId))->toBe(1);
});

/**
 * The emailed-code path carries its own per-code bound; this is the per-ACCOUNT one, which
 * is what stops somebody requesting a fresh code for every handful of guesses.
 */
it('counts a wrong emailed step-up code', function (): void {
    $subject = app(Subjects::class)->create('otp@acme.test', 'Otp', 'a-strong-unbreached-passphrase');
    $auth = app(PlatformAuth::class);

    $auth->holdForOtpStepUp(request(), $subject->id, 'otp@acme.test');

    expect($auth->completeOtpStepUp(request(), '000000'))->toBeFalse()
        ->and(recordedFailures($subject->id))->toBe(1);
});
