<?php

declare(strict_types=1);

use App\Platform\OperatorMfa;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\MfaVerification;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\PlatformRoot;

it('holds a password-only operator at enrollment without relying on an optional policy', function (): void {
    platformRootDeployment();
    $operator = app(PlatformOperators::class)->create('weak@platform.test', 'a-strong-operator-pass', 'Weak');
    signInAsSubject($operator->subject_id);
    expect(app(MfaMandate::class))->toBeInstanceOf(OperatorMfa::class)
        ->and(app(MfaMandate::class)->requiresEnrolment($operator->subject_id))->toBeTrue();
    $this->get(route('platform.environments'))->assertRedirect(route('account'));
    $this->get(route('account'))->assertOk();
});

it('requires strong authentication on an enrolled operator session, including social-only sessions', function (): void {
    $operator = actAsOperator();
    $verification = app(MfaVerification::class);
    expect($verification->requiresVerification($operator->subject_id, ['pwd']))->toBeTrue()
        ->and($verification->requiresVerification($operator->subject_id, ['social']))->toBeTrue()
        ->and($verification->requiresVerification($operator->subject_id, ['pwd', 'otp']))->toBeFalse();
    $this->get(route('platform.environments'))->assertOk();
});

it('does not ask an operator for a second factor on a grant that records no methods', function (): void {
    // A CIBA approval or a device grant: the answer came from a surface that already
    // passed the operator rule, and the token endpoint sees an empty amr.
    $operator = actAsOperator();

    expect(app(MfaVerification::class)->requiresVerification($operator->subject_id, []))->toBeFalse();
});

it('holds an operator again when their last strong factor is removed', function (): void {
    $operator = actAsOperator();
    app(PlatformRoot::class)->run(fn () => app(Mfa::class)->disable($operator->subject_id));
    nextRequest();
    $this->get(route('platform.environments'))->assertRedirect(route('account'));
});
