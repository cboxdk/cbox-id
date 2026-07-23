<?php

declare(strict_types=1);

use App\Mail\AccountInviteMail;
use App\Mail\WorkspacePasswordResetMail;
use App\Platform\AccountAuth;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;

if (! function_exists('provisionAccount')) {
    /**
     * @return array{member: AccountMember, account: Account, environment: Environment}
     */
    function provisionAccount(string $email = 'owner@acme.example'): array
    {
        $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
            accountName: 'Acme',
            ownerEmail: $email,
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        return ['member' => $result->member, 'account' => $result->account, 'environment' => $result->environment];
    }
}

// The accept page screens the password against HaveIBeenPwned — keep it offline.
beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

it('invites a teammate and emails a signed accept link', function (): void {
    Mail::fake();
    ['member' => $owner] = provisionAccount('owner@acme.example');
    session()->put(AccountAuth::SESSION_KEY, $owner->id);

    Volt::test('workspace.members')
        ->set('inviteEmail', 'new@acme.example')
        ->set('inviteName', 'New Person')
        ->call('invite')
        ->assertHasNoErrors();

    $invited = app(AccountMembers::class)->findByEmail('new@acme.example');
    expect($invited)->not->toBeNull()
        ->and($invited->status->value)->toBe('invited');

    Mail::assertSent(AccountInviteMail::class, fn (AccountInviteMail $m): bool => $m->hasTo('new@acme.example'));
});

it('rejects inviting an email that already belongs to a member', function (): void {
    Mail::fake();
    ['member' => $owner] = provisionAccount('owner@acme.example');
    session()->put(AccountAuth::SESSION_KEY, $owner->id);

    Volt::test('workspace.members')
        ->set('inviteEmail', 'owner@acme.example')
        ->call('invite')
        ->assertHasErrors('inviteEmail');

    Mail::assertNothingSent();
});

it('requires a valid signature to reach the accept page', function (): void {
    ['account' => $account] = provisionAccount();
    $invited = app(AccountMembers::class)->invite($account->id, 'new@acme.example', AccountRole::Developer);

    $this->get('/workspace/invite/'.$invited->id.'/accept')->assertForbidden();
});

it('accepts a signed invite, sets a password, and signs in', function (): void {
    ['account' => $account] = provisionAccount();
    $invited = app(AccountMembers::class)->invite($account->id, 'new@acme.example', AccountRole::Developer, 'New');

    $url = URL::temporarySignedRoute('workspace.invite.accept', now()->addDay(), ['member' => $invited->id]);
    $this->get($url)->assertOk()->assertSee('Accept your invitation');

    Volt::test('workspace.accept-invite', ['member' => $invited->id])
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('accept')
        ->assertRedirect(route('workspace.home'));

    $members = app(AccountMembers::class);
    expect($members->find($invited->id)->status->value)->toBe('active')
        ->and($members->verifyPassword($invited->id, 'a-strong-unbreached-passphrase'))->toBeTrue()
        ->and(session()->get(AccountAuth::SESSION_KEY))->toBe($invited->id);
});

it('sends a reset link to an active member and resets on the signed page', function (): void {
    Mail::fake();
    ['member' => $owner] = provisionAccount('owner@acme.example');

    Volt::test('workspace.forgot-password')
        ->set('email', 'owner@acme.example')
        ->call('request')
        ->assertRedirect(route('workspace.login'));

    Mail::assertSent(WorkspacePasswordResetMail::class);

    $url = URL::temporarySignedRoute('workspace.password.reset', now()->addHour(), ['member' => $owner->id]);
    $this->get($url)->assertOk()->assertSee('Set a new password');

    Volt::test('workspace.reset-password', ['member' => $owner->id])
        ->set('password', 'a-fresh-unbreached-passphrase')
        ->call('submit')
        ->assertRedirect(route('workspace.home'));

    expect(app(AccountMembers::class)->verifyPassword($owner->id, 'a-fresh-unbreached-passphrase'))->toBeTrue()
        ->and(session()->get(AccountAuth::SESSION_KEY))->toBe($owner->id);
});

it('makes the reset link single-use and logs out every existing session', function (): void {
    ['member' => $owner] = provisionAccount('owner@acme.example');
    $members = app(AccountMembers::class);

    // A pre-existing session, stamped at the current security version.
    $this->withSession([AccountAuth::SESSION_KEY => $owner->id, AccountAuth::SESSION_VERSION_KEY => $owner->session_version])
        ->get(route('workspace.home'))->assertOk();

    // Reset via a stamp-bound link.
    $url = URL::temporarySignedRoute('workspace.password.reset', now()->addHour(), ['member' => $owner->id, 'v' => $owner->session_version]);
    $this->get($url)->assertOk();
    Volt::test('workspace.reset-password', ['member' => $owner->id])
        ->set('password', 'a-fresh-unbreached-passphrase')
        ->call('submit')
        ->assertRedirect(route('workspace.home'));

    // The SAME link is now dead (the stamp advanced) — single-use.
    $this->get($url)->assertRedirect(route('workspace.login'));

    // And the old session (stamped at the pre-reset version) is logged out.
    $this->withSession([AccountAuth::SESSION_KEY => $owner->id, AccountAuth::SESSION_VERSION_KEY => 0])
        ->get(route('workspace.home'))->assertRedirect(route('workspace.login'));

    expect($members->find($owner->id)->session_version)->toBe(1);
});

it('reveals nothing and sends no mail for an unknown reset email', function (): void {
    Mail::fake();

    Volt::test('workspace.forgot-password')
        ->set('email', 'nobody@nowhere.example')
        ->call('request')
        ->assertRedirect(route('workspace.login'));

    Mail::assertNothingSent();
});

it('turns away an already-accepted invite (replayed link)', function (): void {
    ['account' => $account] = provisionAccount();
    $invited = app(AccountMembers::class)->invite($account->id, 'new@acme.example', AccountRole::Developer);
    app(AccountMembers::class)->activate($invited->id, 'first-accept-passphrase');

    // Re-opening the (still validly-signed) link after acceptance is turned away at
    // the page itself — the member is no longer 'invited'.
    $url = URL::temporarySignedRoute('workspace.invite.accept', now()->addDay(), ['member' => $invited->id]);
    $this->get($url)->assertRedirect(route('workspace.login'));

    // And the framework's activate() is a no-op on an active member regardless, so
    // the first password stands.
    expect(app(AccountMembers::class)->verifyPassword($invited->id, 'first-accept-passphrase'))->toBeTrue();
});
