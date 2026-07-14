<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Platform\Contracts\OperatorMfa;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/** Create an operator whose TOTP factor is enrolled AND confirmed. */
function operatorWithTotp(string $email = 'mfa-op@platform.test'): array
{
    $op = app(PlatformOperators::class)->create($email, 'a-strong-operator-pass', 'MFA Operator');

    $mfa = app(OperatorMfa::class);
    $enrollment = $mfa->enrollTotp($op->id, $email);
    $mfa->confirmTotp($op->id, app(TotpAuthenticator::class)->codeAt($enrollment->secret, time()));

    return [$op, $enrollment->secret];
}

it('holds an operator with confirmed TOTP at the MFA challenge, not the console', function (): void {
    [$op] = operatorWithTotp('challenge@platform.test');

    Volt::test('operator.login')
        ->set('email', 'challenge@platform.test')
        ->set('password', 'a-strong-operator-pass')
        ->call('login')
        ->assertRedirect(route('operator.login.mfa'));

    // A pending marker is stashed, but NO full session — the console stays shut.
    expect(session(OperatorAuth::PENDING_KEY))->toBe($op->id)
        ->and(session(OperatorAuth::SESSION_KEY))->toBeNull();

    $this->get(route('operator.environments'))->assertRedirect(route('operator.login'));
});

it('completes operator login with a valid TOTP code', function (): void {
    [$op, $secret] = operatorWithTotp('good-code@platform.test');

    Volt::test('operator.login')
        ->set('email', 'good-code@platform.test')
        ->set('password', 'a-strong-operator-pass')
        ->call('login');

    // A code from the NEXT time-step (still inside the ±1 skew window) — the
    // enrolment's confirm already consumed the current step, so re-using it would
    // be refused as a replay, exactly as a real login one window later would not be.
    Volt::test('operator.login-mfa')
        ->set('code', app(TotpAuthenticator::class)->codeAt($secret, time() + 30))
        ->call('verify')
        ->assertRedirect(route('operator.environments'));

    // Full session established; the pending marker is cleared.
    expect(session(OperatorAuth::SESSION_KEY))->toBe($op->id)
        ->and(session(OperatorAuth::PENDING_KEY))->toBeNull();
});

it('refuses a wrong TOTP code and rate-limits repeated attempts', function (): void {
    operatorWithTotp('brute-mfa@platform.test');

    Volt::test('operator.login')
        ->set('email', 'brute-mfa@platform.test')
        ->set('password', 'a-strong-operator-pass')
        ->call('login');

    // Five wrong codes consume the window (5/min, keyed to the pending operator).
    foreach (range(1, 5) as $ignored) {
        Volt::test('operator.login-mfa')
            ->set('code', '000000')
            ->call('verify')
            ->assertHasErrors('code')
            ->assertNoRedirect();
    }

    // Locked out — no session was ever established.
    Volt::test('operator.login-mfa')
        ->set('code', '000000')
        ->call('verify')
        ->assertHasErrors('code');

    expect(session(OperatorAuth::SESSION_KEY))->toBeNull();
});

it('completes operator login with a one-time recovery code', function (): void {
    $op = app(PlatformOperators::class)->create('recovery-op@platform.test', 'a-strong-operator-pass', 'Rec');
    $mfa = app(OperatorMfa::class);
    $enrollment = $mfa->enrollTotp($op->id, 'recovery-op@platform.test');
    $mfa->confirmTotp($op->id, app(TotpAuthenticator::class)->codeAt($enrollment->secret, time()));
    $codes = $mfa->generateRecoveryCodes($op->id);

    Volt::test('operator.login')
        ->set('email', 'recovery-op@platform.test')
        ->set('password', 'a-strong-operator-pass')
        ->call('login');

    Volt::test('operator.login-mfa')
        ->set('recoveryCode', $codes[0])
        ->call('useRecoveryCode')
        ->assertRedirect(route('operator.environments'));

    expect(session(OperatorAuth::SESSION_KEY))->toBe($op->id);

    // The code is single-use — it no longer verifies.
    expect($mfa->verifyRecoveryCode($op->id, $codes[0]))->toBeFalse();
});

it('redirects away from the MFA challenge when nothing is pending', function (): void {
    Volt::test('operator.login-mfa')->assertRedirect(route('operator.login'));
});

it('logs an operator without TOTP straight into the console (regression)', function (): void {
    app(PlatformOperators::class)->create('no-mfa@platform.test', 'a-strong-operator-pass', 'Plain');

    Volt::test('operator.login')
        ->set('email', 'no-mfa@platform.test')
        ->set('password', 'a-strong-operator-pass')
        ->call('login')
        ->assertRedirect(route('operator.environments'));

    expect(session(OperatorAuth::SESSION_KEY))->not->toBeNull()
        ->and(session(OperatorAuth::PENDING_KEY))->toBeNull();
});

it('enrolls, confirms and can disable operator TOTP', function (): void {
    $op = app(PlatformOperators::class)->create('enroll@platform.test', 'a-strong-operator-pass', 'Enroller');
    session([OperatorAuth::SESSION_KEY => $op->id]);

    $component = Volt::test('operator.security')->call('enable');
    $secret = $component->get('secret');
    expect($secret)->toBeString()->not->toBeEmpty();

    $component->set('code', app(TotpAuthenticator::class)->codeAt($secret, time()))
        ->call('confirm')
        ->assertHasNoErrors();

    $mfa = app(OperatorMfa::class);
    expect($mfa->hasConfirmedTotp($op->id))->toBeTrue()
        ->and($component->get('recoveryCodes'))->toHaveCount(10)
        ->and($mfa->remainingRecoveryCodes($op->id))->toBe(10);

    // Disable requires re-entering the operator password.
    $component->set('confirmingDisable', true)
        ->set('disablePassword', 'wrong-password')
        ->call('disable')
        ->assertHasErrors('disablePassword');
    expect($mfa->hasConfirmedTotp($op->id))->toBeTrue();

    $component->set('disablePassword', 'a-strong-operator-pass')
        ->call('disable')
        ->assertHasNoErrors();
    expect($mfa->hasConfirmedTotp($op->id))->toBeFalse();
});
