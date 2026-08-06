<?php

declare(strict_types=1);

use App\Mail\AccountInviteMail;
use App\Platform\AccountAuth;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;

if (! function_exists('provisionAccount')) {
    /**
     * @return array{member: Membership, account: Account, environment: Environment}
     */
    function provisionAccount(string $email = 'owner@acme.example'): array
    {
        // The platform root FIRST. An account provisioned without one is in the
        // first-install bootstrap window: its members have no subject, and a member
        // with no subject has nothing to sign in.
        platformRootEnvironment();

        $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
            organizationName: 'Acme',
            ownerEmail: $email,
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        return ['member' => $result->membership, 'subjectId' => $result->owner->id, 'organization' => $result->organization, 'environment' => $result->environment];
    }
}

// The accept page screens the password against HaveIBeenPwned — keep it offline.
beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

it('invites a teammate and emails a signed accept link', function (): void {
    Mail::fake();
    ['member' => $owner] = provisionAccount('owner@acme.example');
    signInAsMember($owner->user_id);

    Volt::test('console.members')
        ->set('inviteEmail', 'new@acme.example')
        ->set('inviteName', 'New Person')
        ->call('invite')
        ->assertHasNoErrors();

    $invited = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('new@acme.example'));
    expect($invited)->not->toBeNull()
        ->and($invited->status->value)->toBe('invited');

    Mail::assertSent(AccountInviteMail::class, fn (AccountInviteMail $m): bool => $m->hasTo('new@acme.example'));
});

it('rejects inviting an email that already belongs to a member', function (): void {
    Mail::fake();
    ['member' => $owner] = provisionAccount('owner@acme.example');
    signInAsMember($owner->user_id);

    Volt::test('console.members')
        ->set('inviteEmail', 'owner@acme.example')
        ->call('invite')
        ->assertHasErrors('inviteEmail');

    Mail::assertNothingSent();
});

it('requires a valid signature to reach the accept page', function (): void {
    ['organization' => $account] = provisionAccount();
    $invited = app(Memberships::class)->invite($account->id, 'new@acme.example', MembershipRole::Developer);

    $this->get('/invite/'.$invited->id.'/accept')->assertForbidden();
});

it('accepts a signed invite, sets a password, and signs in', function (): void {
    ['organization' => $account] = provisionAccount();
    $invited = app(Memberships::class)->invite($account->id, 'new@acme.example', MembershipRole::Developer, 'New');

    $url = URL::temporarySignedRoute('organization.invite.accept', now()->addDay(), ['member' => $invited->id]);
    $this->get($url)->assertOk()->assertSee('Accept your invitation');

    Volt::test('auth.accept-invite', ['member' => $invited->id])
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('accept')
        ->assertRedirect(route('projects'));

    $members = app(Memberships::class);
    expect(freshMembership($invited)->status->value)->toBe('active')
        ->and($members->verifyPassword($invited->id, 'a-strong-unbreached-passphrase'))->toBeTrue()
        // Signed in — asked through the resolver. The session is the subject's; the
        // member is what it resolves to.
        ->and(app(AccountAuth::class)->current()?->id)->toBe($invited->id);
});

/**
 * The account plane had its own forgot/reset pair — `/workspace/forgot-password`, a
 * signed `/workspace/reset-password/{member}`, and a mail template of its own. They are
 * gone, and this is the test that they were a duplicate: the console's reset writes to
 * the SUBJECT, which is the credential of record for an account member, and it lands the
 * same three properties the account pair was written for — the password really changes,
 * the link is single-use, and every session that existed before the reset is dead.
 *
 * Kept HERE, beside the invitation, rather than folded into PasswordResetTest: what is
 * being asserted is that a MEMBER is reachable by the ordinary flow, and that is exactly
 * the claim that would rot silently if it were nobody's test.
 */
it('resets an account member through the console flow and ends their open sessions', function (): void {
    Mail::fake();
    ['member' => $owner] = provisionAccount('owner@acme.example');

    // A pre-existing session, established the way a real sign-in does.
    signInAsMember($owner->user_id);
    $sessionId = (string) session(PlatformAuth::SESSION_KEY);
    $this->get(route('projects'))->assertOk();
    forgetSubjectSession();

    $token = app(PlatformRoot::class)->run(
        fn (): ?string => app(PasswordReset::class)->request('owner@acme.example'),
    );

    expect($token)->toBeString();

    app(PlatformRoot::class)->run(function () use ($token): void {
        Volt::test('auth.reset-password', ['token' => $token])
            ->set('password', 'a-fresh-unbreached-passphrase')
            ->set('password_confirmation', 'a-fresh-unbreached-passphrase')
            ->call('resetPassword')
            ->assertHasNoErrors();
    });

    expect(app(Memberships::class)->verifyPassword($owner->id, 'a-fresh-unbreached-passphrase'))
        ->toBeTrue('the reset did not reach the member\'s credential');

    // The session that existed BEFORE the reset is dead — asserted at the framework ROW,
    // then at the door. A member's browser holds an ordinary subject session, which no
    // column on `account_members` reaches; the reset revokes the subject's sessions, so
    // the old id opens nothing even when the browser is put straight back on it.
    expect(app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->active($sessionId)))
        ->toBeNull('a session opened before the reset outlived it');

    session()->put(PlatformAuth::SESSION_KEY, $sessionId);
    $this->get(route('projects'))->assertRedirect(route('login'));
});

it('turns away an already-accepted invite (replayed link)', function (): void {
    ['organization' => $account] = provisionAccount();
    $invited = app(Memberships::class)->invite($account->id, 'new@acme.example', MembershipRole::Developer);
    app(Memberships::class)->activate($invited->id, 'first-accept-passphrase');

    // Re-opening the (still validly-signed) link after acceptance is turned away at
    // the page itself — the member is no longer 'invited'.
    $url = URL::temporarySignedRoute('organization.invite.accept', now()->addDay(), ['member' => $invited->id]);
    $this->get($url)->assertRedirect(route('login'));

    // And the framework's activate() is a no-op on an active member regardless, so
    // the first password stands.
    expect(app(Memberships::class)->verifyPassword($invited->id, 'first-accept-passphrase'))->toBeTrue();
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
    ['organization' => $account] = provisionAccount('owner@acme.example');

    // A person who already has a Cbox ID, quite apart from this account.
    $outsider = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->create(
        'outsider@elsewhere.test',
        'Outsider',
        'a-strong-unbreached-passphrase',
    ));

    $members = app(Memberships::class);
    [$invited, $invitedSubjectId] = addMember($account->id, MembershipRole::Admin, 'outsider@elsewhere.test');

    expect(freshMembership($invited)?->subject_id)
        ->toBe($outsider->id, 'fixture: the invitation must reuse the existing subject, or this tests nothing');

    // They sign in as themselves — a real, live session, established the ordinary way.
    signInAsSubject($outsider->id);

    expect(app(AccountAuth::class)->check())
        ->toBeFalse('an unaccepted invitation admitted its invitee to the account');

    // Signed in, and with nothing here: they are an ordinary subject of the root, so the
    // Identity platform area simply is not theirs and the console root is where they land.
    // This used to assert a bounce to the sign-in they had just completed, which was the
    // account plane's gate talking rather than the invitation.
    $this->get(route('projects'))->assertRedirect(route('dashboard'));
})->group('security');
