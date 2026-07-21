<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\WorkspaceSudo;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Livewire\Volt\Volt;

if (! function_exists('mfaAccountMember')) {
    function mfaAccountMember(): AccountMember
    {
        return app(AccountProvisioner::class)->provision(new AccountBlueprint(
            accountName: 'Acme',
            ownerEmail: 'owner@acme.example',
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ))->member;
    }
}

it('signs a member without 2FA straight into the workspace', function (): void {
    mfaAccountMember();

    Volt::test('workspace.login')
        ->set('email', 'owner@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('login')
        ->assertRedirect(route('workspace.home'));

    expect(session()->get(AccountAuth::SESSION_KEY))->not->toBeNull();
});

it('challenges a member with 2FA and only completes on a valid code', function (): void {
    $member = mfaAccountMember();
    $mfa = app(AccountMemberMfa::class);
    $totp = app(TotpAuthenticator::class);
    $enroll = $mfa->enrollTotp($member->id, $member->email);
    $mfa->confirmTotp($member->id, $totp->codeAt($enroll->secret, time()));

    // Password step → challenge; NO full session yet, only a pending marker.
    Volt::test('workspace.login')
        ->set('email', 'owner@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('login')
        ->assertRedirect(route('workspace.login.mfa'));

    expect(session()->get(AccountAuth::SESSION_KEY))->toBeNull()
        ->and(session()->get(AccountAuth::PENDING_KEY))->toBe($member->id);

    // A wrong code does not establish a session…
    Volt::test('workspace.login-mfa')->set('code', '000000')->call('verify')->assertHasErrors('code');
    expect(session()->get(AccountAuth::SESSION_KEY))->toBeNull();

    // …a valid code (a later step than the one consumed on confirm) does.
    Volt::test('workspace.login-mfa')
        ->set('code', $totp->codeAt($enroll->secret, time() + 30))
        ->call('verify')
        ->assertRedirect(route('workspace.home'));

    expect(session()->get(AccountAuth::SESSION_KEY))->toBe($member->id);
});

it('redirects the challenge page to login when no 2FA is pending', function (): void {
    $this->get(route('workspace.login.mfa'))->assertRedirect(route('workspace.login'));
});

it('enrolls TOTP from the security page and issues recovery codes', function (): void {
    $member = mfaAccountMember();
    session()->put(AccountAuth::SESSION_KEY, $member->id);
    app(WorkspaceSudo::class)->confirm();
    $totp = app(TotpAuthenticator::class);

    $component = Volt::test('workspace.security')->call('startEnroll');
    $secret = $component->get('secret');
    expect($secret)->not->toBe('');

    $component->set('confirmCode', $totp->codeAt($secret, time()))->call('confirmEnroll')->assertHasNoErrors();

    expect(app(AccountMemberMfa::class)->hasConfirmedTotp($member->id))->toBeTrue()
        ->and($component->get('recoveryCodes'))->toHaveCount(10);
});
