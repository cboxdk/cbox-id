<?php

declare(strict_types=1);

use App\Mail\AccountInviteMail;
use App\Mail\WorkspacePasswordResetMail;
use App\Platform\AccountAuth;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;
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
        // The platform root FIRST. An account provisioned without one is in the
        // first-install bootstrap window: its members have no subject, and a member
        // with no subject has nothing to sign in.
        platformRootEnvironment();

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
    signInAsMember($owner);

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
    signInAsMember($owner);

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
        // Signed in — asked through the resolver. The session is the subject's; the
        // member is what it resolves to.
        ->and(app(AccountAuth::class)->current()?->id)->toBe($invited->id);
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
        ->and(app(AccountAuth::class)->current()?->id)->toBe($owner->id);
});

it('makes the reset link single-use and logs out every existing session', function (): void {
    ['member' => $owner] = provisionAccount('owner@acme.example');
    $members = app(AccountMembers::class);

    // A pre-existing session, established the way a real sign-in does.
    signInAsMember($owner);
    $sessionId = (string) session(PlatformAuth::SESSION_KEY);
    $this->get(route('workspace.home'))->assertOk();

    // Reset via a stamp-bound link.
    $url = URL::temporarySignedRoute('workspace.password.reset', now()->addHour(), ['member' => $owner->id, 'v' => $owner->session_version]);
    $this->get($url)->assertOk();
    Volt::test('workspace.reset-password', ['member' => $owner->id])
        ->set('password', 'a-fresh-unbreached-passphrase')
        ->call('submit')
        ->assertRedirect(route('workspace.home'));

    // The SAME link is now dead (the stamp advanced) — single-use.
    $this->get($url)->assertRedirect(route('workspace.login'));

    // And the session that existed BEFORE the reset is dead.
    //
    // Asserted at the framework ROW, then at the door. The stamp on the member row used
    // to be what killed it, and it cannot be any more: a member's browser holds an
    // ordinary subject session, which no column on `account_members` reaches. The reset
    // revokes the subject's sessions instead — so the old id opens nothing even when the
    // browser is put straight back on it.
    expect(app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->active($sessionId)))
        ->toBeNull('a session opened before the reset outlived it');

    session()->put(PlatformAuth::SESSION_KEY, $sessionId);
    $this->get(route('workspace.home'))->assertRedirect(route('workspace.login'));

    // The stamp still advances — that is what makes the LINK single-use, which is a
    // different job from ending a session and the one it still has.
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

/**
 * An invitation is an offer, not a membership — and the person it is offered to may
 * already have a Cbox ID.
 *
 * That is the case this guards. Inviting an address that already has a subject in the
 * platform root REUSES that subject rather than minting one (anything else would be
 * "adding someone's email to an account you control resets their password"), so the
 * invitee holds a live, active credential the moment the invitation is sent. The member
 * row is what says they have not accepted yet.
 *
 * The console therefore cannot infer membership from "this session belongs to somebody
 * with a member row". It has to read the row's STATUS on every request — which is the
 * check that used to ride on there being a separate member session at all, and which
 * nothing else would notice the loss of: an invited member with a fresh subject has a
 * DEACTIVATED subject and is held out by the credential, so only the pre-existing-account
 * case reaches this.
 */
it('does not admit an invited member who has not accepted, even holding a live session', function (): void {
    ['account' => $account] = provisionAccount('owner@acme.example');

    // A person who already has a Cbox ID, quite apart from this account.
    $outsider = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->create(
        'outsider@elsewhere.test',
        'Outsider',
        'a-strong-unbreached-passphrase',
    ));

    $members = app(AccountMembers::class);
    $invited = $members->invite($account->id, 'outsider@elsewhere.test', AccountRole::Admin);

    expect($members->find($invited->id)?->subject_id)
        ->toBe($outsider->id, 'fixture: the invitation must reuse the existing subject, or this tests nothing');

    // They sign in as themselves — a real, live session, established the ordinary way.
    signInAsSubject($outsider->id);

    expect(app(AccountAuth::class)->check())
        ->toBeFalse('an unaccepted invitation admitted its invitee to the workspace');

    $this->get(route('workspace.home'))->assertRedirect(route('workspace.login'));
})->group('security');
