<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Platform\Contracts\OperatorMfa;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('refuses the operator security screen to someone who is not an operator', function (): void {
    platformRootDeployment();
    app(PlatformOperators::class)->create('someone@platform.test', 'a-strong-operator-pass', 'Op');

    Volt::test('operator.security')->assertStatus(404);
});
