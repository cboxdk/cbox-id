<?php

declare(strict_types=1);

use App\Mail\AccountInviteMail;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;

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

    // NO SUBJECT YET, and that is the change worth naming: the account plane created the
    // person up front as a member row in an `invited` state, so an invitation that was
    // never accepted still put an identity on the install. An invitation is its own record;
    // the subject is created when it is redeemed.
    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('new@acme.example')))
        ->toBeNull();

    expect(app(PlatformRoot::class)->run(fn () => app(Invitations::class)->pending($owner->organization_id)))
        ->toHaveCount(1);

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

    $pending = app(PlatformRoot::class)->run(
        fn () => app(Invitations::class)->invite($account->id, 'new@acme.example', MembershipRole::Developer),
    );

    // The token alone is not enough: the link is SIGNED, and an invitation token pasted
    // into the address bar without the signature is refused before the page runs.
    $this->get('/invite/'.$pending->token.'/accept')->assertForbidden();
});

it('accepts a signed invite, sets a password, and signs in', function (): void {
    ['organization' => $account] = provisionAccount();

    $pending = app(PlatformRoot::class)->run(
        fn () => app(Invitations::class)->invite($account->id, 'new@acme.example', MembershipRole::Developer),
    );

    $url = URL::temporarySignedRoute('organization.invite.accept', now()->addDay(), ['token' => $pending->token]);
    $this->get($url)->assertOk()->assertSee('Accept your invitation');

    Volt::test('auth.accept-invite', ['token' => $pending->token])
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('accept')
        ->assertRedirect(route('projects'));

    // The MEMBERSHIP exists now, which it did not before acceptance — that is the whole
    // difference between an invitation and a member. It used to be one row moving from
    // `invited` to `active`; it is a record being redeemed into a membership.
    $subject = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('new@acme.example'));

    expect($subject)->not->toBeNull()
        ->and(app(PlatformRoot::class)->run(
            fn () => app(Memberships::class)->of($account->id, $subject->id)?->role,
        ))->toBe(MembershipRole::Developer)
        // Signed in on the subject's own session — asked at the session key rather than
        // through CurrentUser, which the middleware populates on the NEXT request and which
        // a component driven directly never reaches.
        ->and(session(PlatformAuth::SESSION_KEY))->not->toBeNull();
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

    expect(app(PlatformRoot::class)->run(
        fn (): bool => app(Subjects::class)->verifyPassword($owner->user_id, 'a-fresh-unbreached-passphrase'),
    ))->toBeTrue('the reset did not reach the person\'s credential');

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

    $pending = app(PlatformRoot::class)->run(
        fn () => app(Invitations::class)->invite($account->id, 'new@acme.example', MembershipRole::Developer),
    );

    $url = URL::temporarySignedRoute('organization.invite.accept', now()->addDay(), ['token' => $pending->token]);

    Volt::test('auth.accept-invite', ['token' => $pending->token])
        ->set('password', 'first-accept-passphrase')
        ->call('accept');

    // Re-opening the (still validly-signed) link is turned away at the page itself: the
    // TOKEN is single-use, so the second visit finds no invitation to redeem. It used to be
    // turned away because a member row had left the `invited` state — a status check, which
    // is the weaker of the two: a status can be written back.
    $this->get($url)->assertRedirect(route('login'));

    // …and the password chosen at the first acceptance still stands: a replayed link
    // cannot re-set it, which is the outcome that actually matters.
    $subject = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('new@acme.example'));

    expect(app(PlatformRoot::class)->run(
        fn (): bool => app(Subjects::class)->verifyPassword($subject->id, 'first-accept-passphrase'),
    ))->toBeTrue();
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
it('does not admit an invited person who has not accepted, even holding a live session', function (): void {
    ['organization' => $account] = provisionAccount('owner@acme.example');

    // A person who already has a Cbox ID, quite apart from this organization.
    $outsider = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->create(
        'outsider@elsewhere.test',
        'Outsider',
        'a-strong-unbreached-passphrase',
    ));

    // INVITED, not added. The account plane wrote a member row in an `invited` state, so an
    // unaccepted invitation was already a row on the roster and the question was whether
    // its status held the person out. An invitation is its own record now: until it is
    // redeemed there is no membership at all, which is a stronger version of the same
    // guarantee and the reason this test reads differently than it did.
    app(PlatformRoot::class)->run(
        fn () => app(Invitations::class)->invite($account->id, 'outsider@elsewhere.test', MembershipRole::Admin),
    );

    expect(app(PlatformRoot::class)->run(fn () => app(Memberships::class)->of($account->id, $outsider->id)))
        ->toBeNull('an unaccepted invitation granted a membership');

    // They sign in as themselves — a real, live session, established the ordinary way.
    signInAsSubject($outsider->id);

    // Signed in, and with nothing here: they are an ordinary subject of the root, so the
    // Identity platform area simply is not theirs and the console root is where they land.
    // This used to assert a bounce to the sign-in they had just completed, which was the
    // account plane's gate talking rather than the invitation.
    $this->get(route('projects'))->assertRedirect(route('dashboard'));
})->group('security');
