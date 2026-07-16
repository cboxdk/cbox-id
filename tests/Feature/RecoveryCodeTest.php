<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

function signedInOwner(): string
{
    $subject = app(Subjects::class)->create('mfa-owner@acme.test', 'MFA Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-mfa'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');

    return $subject->id;
}

it('issues recovery codes when the user enables 2FA', function (): void {
    $userId = signedInOwner();

    // Enabling 2FA is a sensitive action (it overwrites any existing factor) —
    // confirm the step-up first.
    app(Sudo::class)->confirm();
    $component = Volt::test('account')->call('enable');
    $secret = $component->get('secret');

    $component->set('code', app(TotpAuthenticator::class)->codeAt($secret, time()))
        ->call('confirm')
        ->assertHasNoErrors();

    // Ten recovery codes are shown once and counted as remaining.
    expect($component->get('recoveryCodes'))->toHaveCount(10)
        ->and(app(Mfa::class)->remainingRecoveryCodes($userId))->toBe(10);
});

it('regenerates recovery codes and invalidates the old set', function (): void {
    $userId = signedInOwner();
    $mfa = app(Mfa::class);

    // Enable 2FA first (recovery requires a confirmed factor). Sensitive → step-up.
    app(Sudo::class)->confirm();
    $component = Volt::test('account')->call('enable');
    $secret = $component->get('secret');
    $component->set('code', app(TotpAuthenticator::class)->codeAt($secret, time()))->call('confirm');

    $old = $component->get('recoveryCodes');

    // Regenerating is a sensitive action — confirm step-up first.
    app(Sudo::class)->confirm();
    $component->call('regenerateRecoveryCodes');
    $new = $component->get('recoveryCodes');

    expect($new)->toHaveCount(10)
        ->and($new)->not->toBe($old)
        ->and($mfa->verifyRecoveryCode($userId, $old[0]))->toBeFalse()   // old set dead
        ->and($mfa->verifyRecoveryCode($userId, $new[0]))->toBeTrue();   // new set live
});
