<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Platform\Contracts\OperatorMfa;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/*
 * This file used to be six tests about the OPERATOR LOGIN's second factor: held at the
 * challenge, wrong code rate-limited, recovery code single-use, no-TOTP straight through.
 * That door is gone. An operator is a subject, they sign in where everyone else does, and
 * the second factor that gates their sign-in is the SUBJECT's — enrolled, challenged,
 * rate-limited and recovered by the identity stack that already had all of it, and
 * covered by that stack's own tests. Re-testing a login form that no longer exists would
 * have been six passing tests about nothing.
 *
 * What is left is the operator TOTP screen itself, which still enrols a factor against
 * `platform_operators`. NOTE: nothing challenges that factor any more — see the report on
 * this change. The screen is tested here as it behaves, not as it ought to.
 */
it('enrolls, confirms and can disable operator TOTP', function (): void {
    $op = actAsOperator('enroll@platform.test');

    $component = Volt::test('platform.security')->call('enable');
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

it('refuses the operator security screen to someone who is not an operator', function (): void {
    platformRootDeployment();
    app(PlatformOperators::class)->create('someone@platform.test', 'a-strong-operator-pass', 'Op');

    Volt::test('platform.security')->assertStatus(404);
});

/**
 * Regenerating operator recovery codes needs the password too, and both password checks
 * on this page are throttled.
 *
 * `regenerateRecoveryCodes()` asked for nothing but a live operator session with a
 * confirmed factor — and what it renders is ten valid single-use second factors that
 * SURVIVE revoking every session. That is precisely the outcome `disable()`'s password
 * check exists to prevent, reached by the sibling twenty lines away that did not ask.
 * A hijacked-but-stale operator cookie was enough.
 *
 * `disable()` had the password and no limiter, while `confirm()` immediately above had a
 * limiter and no password. Each had exactly the half the other was missing, so this pins
 * both halves on both actions.
 */
it('requires the operator password to regenerate recovery codes', function (): void {
    $op = actAsOperator('regen@platform.test');
    $mfa = app(OperatorMfa::class);

    $component = Volt::test('platform.security')->call('enable');
    $component->set('code', app(TotpAuthenticator::class)->codeAt($component->get('secret'), time()))->call('confirm');

    $first = $component->get('recoveryCodes');
    expect($first)->toHaveCount(10);

    // No password: refused, and the existing codes are untouched — a failed attempt must
    // not invalidate the set the operator is still relying on.
    $component->set('regeneratePassword', '')->call('regenerateRecoveryCodes')->assertHasErrors('regeneratePassword');
    expect($mfa->verifyRecoveryCode($op->id, $first[0]))->toBeTrue('a refused regenerate still burned the old codes');
})->group('security');

it('throttles the operator password checks, on both actions', function (): void {
    $op = actAsOperator('throttle@platform.test');

    $component = Volt::test('platform.security')->call('enable');
    $component->set('code', app(TotpAuthenticator::class)->codeAt($component->get('secret'), time()))->call('confirm');

    // `verifyPassword()` is bcrypt, so unbounded guessing here is slow rather than
    // instant — which also makes it a free way to pin a CPU. Five, then the budget.
    for ($i = 0; $i < 6; $i++) {
        $component->set('disablePassword', 'wrong-'.$i)->set('confirmingDisable', true)->call('disable');
    }

    // Asserted on the LIMITER, not on the rendered message.
    //
    // The message is what a person sees, so it was the first thing I reached for — and it
    // is the wrong assertion twice over. Livewire accumulates the error bag across calls
    // in one testable, so `first()` answers with attempt one's "that password is
    // incorrect" and would call a throttle that never fired a success; and reading the
    // whole bag turned out to depend on test order in a way I could not explain, which is
    // not something to ship inside a security assertion.
    //
    // The limiter IS the guard. If it is engaged, the next attempt is refused — that is
    // the property, stated directly, and it goes red the moment `RateLimiter::hit()` stops
    // being called.
    expect(RateLimiter::tooManyAttempts('operator-password|disablePassword|'.$op->id, 5))
        ->toBeTrue('the password check burned six attempts without engaging the limiter');
})->group('security');
