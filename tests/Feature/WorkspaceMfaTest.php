<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\PlatformAuth;
use App\Platform\WorkspaceSudo;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Livewire\Volt\Volt;

beforeEach(function (): void {
    // These render product pages, which presuppose an installed deployment.
    installedDeployment();
});

if (! function_exists('mfaAccountMember')) {
    function mfaAccountMember(): AccountMember
    {
        // The platform root FIRST. An account provisioned without one is in the
        // first-install bootstrap window: its members have no subject, and a member
        // with no subject has nothing to sign in.
        platformRootEnvironment();

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

    // A session exists — asked through the resolver, because the session is the
    // subject's now and the member is what it resolves TO.
    expect(app(AccountAuth::class)->check())->toBeTrue();
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

    expect(session()->get(PlatformAuth::SESSION_KEY))->toBeNull()
        ->and(session()->get(AccountAuth::PENDING_KEY))->toBe($member->id);

    // A wrong code does not establish a session…
    Volt::test('workspace.login-mfa')->set('code', '000000')->call('verify')->assertHasErrors('code');
    expect(session()->get(PlatformAuth::SESSION_KEY))->toBeNull();

    // …a valid code (a later step than the one consumed on confirm) does.
    Volt::test('workspace.login-mfa')
        ->set('code', $totp->codeAt($enroll->secret, time() + 30))
        ->call('verify')
        ->assertRedirect(route('workspace.home'));

    // The session that resulted really is this member's — and it is a SUBJECT session,
    // which is the whole point: there is nothing else left for it to be.
    expect(session()->get(PlatformAuth::SESSION_KEY))->not->toBeNull()
        ->and(app(AccountAuth::class)->current()?->id)->toBe($member->id);
});

it('redirects the challenge page to login when no 2FA is pending', function (): void {
    $this->get(route('workspace.login.mfa'))->assertRedirect(route('workspace.login'));
});

it('enrolls TOTP from the security page and issues recovery codes', function (): void {
    $member = mfaAccountMember();
    signInAsMember($member);
    app(WorkspaceSudo::class)->confirm();
    $totp = app(TotpAuthenticator::class);

    $component = Volt::test('workspace.security')->call('startEnroll');

    // Read the secret off the RENDERED page, not off the component.
    //
    // It is deliberately not a public property any more: Livewire serialises those into
    // the wire snapshot in the DOM and echoes them back in the body of every subsequent
    // update, so a TOTP secret held there rode every round trip on this page. The page
    // must still show it once — that is what enrolment is — so that is where the test
    // looks, which also means the test now proves the secret actually reaches the user.
    preg_match('/<code[^>]*>([A-Z2-7]{16,})<\/code>/', $component->html(), $shown);
    $secret = $shown[1] ?? '';

    expect($secret)->not->toBe('', 'the enrolment secret never reached the page');

    $component->set('confirmCode', $totp->codeAt($secret, time()))->call('confirmEnroll')->assertHasNoErrors();

    expect(app(AccountMemberMfa::class)->hasConfirmedTotp($member->id))->toBeTrue()
        // Recovery codes are shown once, on the render that creates them — and are not
        // in the snapshot either, so they are counted where they are displayed.
        ->and(app(AccountMemberMfa::class)->remainingRecoveryCodes($member->id))->toBe(10);
});

/**
 * Neither the enrolment secret nor the recovery codes may enter the wire snapshot.
 *
 * Livewire serialises public properties into the snapshot embedded in the DOM and echoes
 * them back in the body of every subsequent /livewire/update until they are reset — and
 * `$recoveryCodes` was reset only on `disable()`, so after enrolment the full MFA-bypass
 * set rode every round trip on the page, into request-body logs and APM traces along the
 * way. The API-keys page next door already documents exactly this reasoning; this page
 * was the outlier.
 *
 * The pending secret has to survive one round trip, so it lives in the session rather
 * than on the component: server-side, and already this page's trust anchor.
 */
it('keeps the enrolment secret and recovery codes out of the wire snapshot', function (): void {
    $member = mfaAccountMember();
    signInAsMember($member);
    app(WorkspaceSudo::class)->confirm();

    $component = Volt::test('workspace.security')->call('startEnroll');

    // The secret IS on the page — that is what enrolment is — but not in the snapshot.
    preg_match('/<code[^>]*>([A-Z2-7]{16,})<\/code>/', $component->html(), $shown);
    $secret = $shown[1] ?? '';

    expect($secret)->not->toBe('');

    $snapshot = (string) json_encode($component->snapshot);

    expect(str_contains($snapshot, $secret))
        ->toBeFalse('the TOTP secret was serialised into the DOM snapshot');

    $component->set('confirmCode', app(TotpAuthenticator::class)->codeAt($secret, time()))
        ->call('confirmEnroll')
        ->assertHasNoErrors();

    $codes = app(AccountMemberMfa::class)->remainingRecoveryCodes($member->id);

    expect($codes)->toBe(10);

    // A separate statement, not chained: chained through `->and(...)` this assertion
    // silently did not run, and the file passed with the property public again.
    $afterConfirm = (string) json_encode($component->snapshot);

    expect(str_contains($afterConfirm, 'recoveryCodes'))
        ->toBeFalse('the recovery codes were serialised into the DOM snapshot');
});
