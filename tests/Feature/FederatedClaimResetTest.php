<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\AccountExistsForEmail;
use Cbox\Id\Identity\Exceptions\InvalidPasswordReset;
use Cbox\Id\Identity\Exceptions\PolicyViolation;
use Cbox\Id\Identity\Models\IdentityLink;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| A reset link proves the mailbox, and evicts whoever claimed the address before it
|--------------------------------------------------------------------------
|
| An organization's own IdP may assert any address, and a first federated sign-in creates
| an account under it, unconfirmed. When the real owner then reset their way into that
| account, the identity that had asserted their address stayed linked and kept signing
| into it. Sign-in links and invitations already hand such an account to the mailbox; the
| reset now does too.
*/

beforeEach(function (): void {
    Mail::fake();
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
});

function squattingAssertion(): FederatedPrincipal
{
    return new FederatedPrincipal('saml', 'attacker-nameid', 'owner@victim.test', 'Not The Owner');
}

it('drops the federated identity that claimed an address once its owner resets the password', function (): void {
    $account = app(Subjects::class)->provisionFederated(squattingAssertion());
    expect($account->emailVerified)->toBeFalse();

    // Whatever the claimant set on the account while it held it.
    $factor = app(Mfa::class)->enrollTotp($account->id, 'owner@victim.test');
    confirmTotpForTest($account->id, $factor->secret);
    $claimantSession = app(SessionManager::class)->start($account->id, null, ['sso']);

    $token = (string) app(PasswordReset::class)->request('owner@victim.test');
    app(PasswordReset::class)->reset($token, 'the-owners-own-passphrase');

    $owned = app(Subjects::class)->find($account->id);

    expect($owned?->emailVerified)->toBeTrue()
        ->and(app(Subjects::class)->verifyPassword($account->id, 'the-owners-own-passphrase'))->toBeTrue()
        ->and(IdentityLink::query()->where('user_id', $account->id)->exists())->toBeFalse()
        ->and(MfaFactor::query()->where('user_id', $account->id)->exists())->toBeFalse()
        ->and(app(SessionManager::class)->active($claimantSession->id))->toBeNull();

    // The same assertion again: it is no longer this account's way in. It meets an
    // existing address, which only an explicit link can join.
    expect(fn () => app(Subjects::class)->provisionFederated(squattingAssertion()))
        ->toThrow(AccountExistsForEmail::class);
});

it('resets a confirmed account without touching its linked sign-ins', function (): void {
    $subject = app(Subjects::class)->create('owner@victim.test', 'Owner', 'old-password-1234');
    app(Subjects::class)->markEmailVerified($subject->id, 'owner@victim.test');
    app(Subjects::class)->link($subject->id, new FederatedPrincipal('github', 'owners-github'));

    $token = (string) app(PasswordReset::class)->request('owner@victim.test');
    app(PasswordReset::class)->reset($token, 'the-owners-own-passphrase');

    expect(app(Subjects::class)->verifyPassword($subject->id, 'the-owners-own-passphrase'))->toBeTrue()
        ->and(IdentityLink::query()->where('user_id', $subject->id)->count())->toBe(1);
});

it('changes nothing when the reset of an unconfirmed account is refused', function (): void {
    $account = app(Subjects::class)->provisionFederated(squattingAssertion());

    $token = (string) app(PasswordReset::class)->request('owner@victim.test');

    expect(fn () => app(PasswordReset::class)->reset($token, 'short'))->toThrow(PolicyViolation::class);

    expect(app(Subjects::class)->find($account->id)?->emailVerified)->toBeFalse()
        ->and(IdentityLink::query()->where('user_id', $account->id)->exists())->toBeTrue();
});

it('refuses a spent token on an unconfirmed account like any other', function (): void {
    app(Subjects::class)->provisionFederated(squattingAssertion());
    $token = (string) app(PasswordReset::class)->request('owner@victim.test');
    app(PasswordReset::class)->reset($token, 'the-owners-own-passphrase');

    expect(fn () => app(PasswordReset::class)->reset($token, 'another-passphrase-9876'))
        ->toThrow(InvalidPasswordReset::class);
});
