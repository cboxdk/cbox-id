<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function signedInOwner(): string
{
    $subject = app(Subjects::class)->create('mfa-owner@acme.test', 'MFA Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-mfa'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    // The session as a real sign-in leaves it — a live row AND the key in the request
    // session. `CurrentUser::set()` alone is enough for a component driven in-process and
    // nothing at all to an HTTP request, which would be redirected to the door.
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    return $subject->id;
}

it('issues recovery codes when the user enables 2FA', function (): void {
    $userId = signedInOwner();

    // Enabling 2FA is a sensitive action (it overwrites any existing factor) — the route
    // carries `sudo`, so the step-up is confirmed first. That gate has its own coverage in
    // SudoTest; here it is held open so this test is about the codes.
    app(Sudo::class)->confirm();

    /*
     * THE SECRET COMES BACK ON THE FLASH CHANNEL, never as a page prop — props are written
     * into the browser's history entry, and this one produces every code the account will
     * ever accept. The flash is written into the session and spent by the next render,
     * which is the behaviour the channel exists for.
     */
    beginMfaEnrolment()->assertSessionHasNoErrors();
    $secret = flashed('mfaSecret');

    confirmMfaEnrolment(app(TotpAuthenticator::class)->codeAt($secret, time()))
        ->assertSessionHasNoErrors();

    // Ten recovery codes, shown once and counted as remaining.
    expect(app(Mfa::class)->remainingRecoveryCodes($userId))->toBe(10);
});

it('regenerates recovery codes and invalidates the old set', function (): void {
    $userId = signedInOwner();
    $mfa = app(Mfa::class);

    // Enable 2FA first (recovery requires a confirmed factor). Sensitive → step-up.
    app(Sudo::class)->confirm();

    beginMfaEnrolment();
    $secret = flashed('mfaSecret');

    confirmMfaEnrolment(app(TotpAuthenticator::class)->codeAt($secret, time()))
        ->assertSessionHasNoErrors();

    $old = flashed('recoveryCodes');

    regenerateRecoveryCodes()->assertSessionHasNoErrors();

    $new = flashed('recoveryCodes');

    expect($new)->toHaveCount(10)
        ->and($new)->not->toBe($old)
        ->and($mfa->verifyRecoveryCode($userId, $old[0]))->toBeFalse()   // old set dead
        ->and($mfa->verifyRecoveryCode($userId, $new[0]))->toBeTrue();   // new set live
});
