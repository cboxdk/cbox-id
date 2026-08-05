<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\CredentialVerdict;
use App\Providers\PlatformServiceProvider;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;
use Throwable;

/**
 * Which ACCOUNT the signed-in subject belongs to, and with what role.
 *
 * There is no account-member session any more, and no account console either: the pages
 * that were one are an area of the console, admitted by
 * {@see Console\ConsoleScope::accountRole()}, which is the principal caller of the
 * resolver below. This class is what is left once both ideas are removed.
 *
 * A DOOR IT NO LONGER IS, and no longer keeps one for the sake of the argument. `attempt()`
 * verified a member's password and `adoptFederated()` admitted one an identity provider had
 * vouched for; nothing in the application called either, and both are gone. The one sign-in
 * is `/login`, which authenticates the SUBJECT — the credential of record.
 *
 * Keeping them was defensible while they were the only written statement of what
 * {@see MemberCredentialGate} was for, and it stopped being defensible once every rule they
 * enforced was checked, one at a time, against the door that is actually reachable:
 * `PlatformAuth::attemptPassword()` asks the lockout, `AdminPasswords::hasExpired()` and the
 * SSO mandate — the same `localSignInAllowedFor()` the gate asked — of the same subject.
 * Not one rule was lost by deleting them. What was left was a second password door, with its
 * own lockout counter and its own account-active check, one route registration away from
 * being reachable, held upright by tests that could only reach it themselves.
 *
 * A separate door is never a boundary, and it is usually the weakest one BECAUSE it is
 * separate: this is the same lesson the operator credential store taught, one plane along.
 *
 * THE SECOND FACTOR IS THE SUBJECT'S, and no longer has an account-plane copy. There used
 * to be one, in `account_mfa_factors` and its companions, consulted by both of those doors.
 * It was already unenforceable before those checks were removed: `/login` serves the root,
 * so a member with an account-plane TOTP signed in there against their subject credential
 * and reached the console with a password alone. Deleting it removed the APPEARANCE of a
 * factor, not a factor — the enrolment UI had gone with the account door, no row was ever
 * written again, and a store that can still be written but is never checked is worse than
 * none. `PlatformAuth::attemptPassword()` holds a subject with a confirmed TOTP at the
 * challenge, and an account member is an ordinary subject, so that is where a member's
 * second factor is enrolled and enforced.
 *
 * An account member is an ordinary subject in the platform-root
 * environment ({@see docs/core-concepts/unified-account-identity.md}); the member row says
 * WHICH ACCOUNT that subject belongs to and with what {@see AccountRole}. That is a
 * lookup, not an identity, so it has no business being session state: `account_members`
 * has a UNIQUE `subject_id`, so "which account" has exactly one answer and the session
 * cannot hold a different one.
 *
 * Three stores for one person is what this replaces, and every seam between them was a
 * bug. An operator signed in and was bounced back to the sign-in forever because the door
 * wrote a member session and the gate asked for a subject one. The member door grew an
 * operator branch so somebody with no membership could get in at all. The console's gate
 * grew an operator clause to let them land. {@see Console\ConsoleScope} asked all three
 * stores in turn. None of those were separate problems, and the same is true of the
 * console that stood on top of them.
 *
 * WHAT LOGS A MEMBER OUT EVERYWHERE. It used to be `session_version` on the member row,
 * re-checked here on every resolve. That check is gone because the thing it guarded is
 * gone — the browser holds a subject session now, which a stamp on the member row cannot
 * reach. Its replacement is stronger and lives at the right altitude:
 * `PasswordResetService::reset()` calls `revokeAllForUser()` on the subject inside the
 * same transaction that writes the new hash, so the next request finds no session at all
 * rather than a session it declines to honour. The stamp itself stays: it is also what
 * makes a reset LINK single-use.
 *
 * This used to name `DatabaseAccountMembers::resetPassword()`, an account-plane reset that
 * no page calls — the reset form goes to the framework's `PasswordReset` contract. The
 * property was right and the citation was not, which is the more dangerous half: a reader
 * checking the claim finds a method that does revoke sessions and stops looking.
 *
 * There is deliberately NO "current environment" session state here: environments are
 * resolved statelessly from the request host ({slug}.base_domain or a custom domain), so
 * the projects page is a pure launchpad that links OUT to each environment's own domain.
 */
final class AccountAuth
{
    /**
     * Per-request memo, KEYED ON THE SUBJECT ID it was resolved from — the same shape,
     * and for the same reasons, as {@see EnvironmentAdminAuth::current()}.
     *
     * `current()` is asked by {@see Console\ConsoleScope}, by the rail's feature gates and
     * by each page's own guard. Every one of those is a lookup that crosses into the
     * PLATFORM ROOT — a scope switch plus the member row plus its account — and one page
     * paid it nine times.
     *
     * The key is the input rather than a plain "computed once" flag, deliberately. The
     * signed-in subject can change within a request (an establish() mid-request is exactly
     * that), and a memo that outlived it would answer for the PREVIOUS person. Re-deriving
     * the key each call collapses the repeats without ever answering about somebody else.
     *
     * This class is bound `scoped` ({@see PlatformServiceProvider}) so the
     * memo lives exactly one request; without that binding every `app(AccountAuth::class)`
     * would be a fresh object and an instance memo would never be hit.
     */
    private ?string $memoSubjectId = null;

    private ?AccountMember $memoMember = null;

    public function __construct(
        private readonly PlatformRoot $platformRoot,
        private readonly PlatformAuth $platformAuth,
        private readonly AccountMembers $members,
        private readonly MemberCredentialGate $gate,
        private readonly AccountActivity $activity,
        private readonly CurrentUser $current,
        private readonly Subjects $subjects,
    ) {}

    /**
     * Whether a mandate refuses this member a session, for a door that has already proved
     * a factor which is not a password — today, an accepted invitation.
     *
     * Takes a member ID because that is what those doors are holding: the passkey
     * assertion identifies a credential, and the invitation and reset links are signed for
     * one member. The gate does the rest, in the platform root's scope, where the
     * memberships and policies are.
     *
     * A member id that resolves to nothing answers Admitted rather than inventing a
     * refusal: {@see establish()} is the one place that decides there is nobody to sign
     * in, and it already returns false — a second opinion here would send somebody to a
     * mandate screen naming no organization, over a member that does not exist.
     */
    public function admitsFactor(string $memberId): CredentialVerdict
    {
        $member = $this->members->find($memberId);

        return $member === null
            ? CredentialVerdict::Admitted
            : $this->gate->admitsFactor($member);
    }

    /**
     * The platform-root subject behind a member, or null when there is not one.
     *
     * Exposed for the doors that have to name the person to something OUTSIDE this class —
     * {@see SsoRefusal} carries a subject, because the screen that renders a mandate
     * resolves it through {@see SsoMandates}, and memberships hang off subjects rather than
     * off account members. Deliberately not "the member": handing a member row to a
     * pre-session screen would hand it an account id and an email it has no business
     * holding.
     */
    public function subjectFor(string $memberId): ?string
    {
        $subjectId = $this->members->find($memberId)?->subject_id;

        return is_string($subjectId) && $subjectId !== '' ? $subjectId : null;
    }

    /**
     * Establish the session for a member — the single place a member sign-in creates
     * session state, so the doors that admit one can never diverge.
     *
     * It mints a SUBJECT session, through the same {@see PlatformAuth::establish()} every
     * other door uses: the multi-account set, the framework session row, session-fixation
     * rotation and the step-up drop are that one implementation rather than a second copy.
     *
     * Returns false when there is nothing to sign in — no member, or a member with no
     * subject. The second is the first-install bootstrap window (an account provisioned
     * before the deployment had a platform root, so there was nowhere to put the subject).
     * An installed deployment cannot produce it: the installer stamps the root first, and
     * an unclaimed one answers every page with its first-run screen. A caller must treat
     * false as a refusal — reporting success with no session is the loop this whole change
     * removes.
     *
     * @param  list<string>  $amr  how the person authenticated
     */
    public function establish(string $memberId, array $amr = ['pwd']): bool
    {
        $member = $this->members->find($memberId);
        $subjectId = $member?->subject_id;

        if ($member === null || ! is_string($subjectId) || $subjectId === '') {
            return false;
        }

        $this->members->touchLogin($memberId);

        // The row this request is about to resolve has just been written, and the session
        // is about to name somebody it did not name a moment ago.
        $this->forgetMemo();

        // The pending marker is spent whether or not a factor was involved: a full
        // session must never leave a redeemable second-factor handle behind it in the
        // (data-preserving) session. establish() below drops the step-up windows for the
        // same reason, and drops them for every door at once.
        $this->platformRoot->run(function () use ($subjectId, $amr): bool {
            $this->platformAuth->establish(request(), $subjectId, $amr);

            return true;
        });

        // The request that signs someone in cannot see its own result through
        // CurrentUser: the middleware populated it at the top of this request, when
        // nobody was signed in. Every caller redirects, so the next request would fix
        // itself — but {@see current()} reads CurrentUser, and a component that asks who
        // it just signed in would be told "nobody".
        $subject = $this->platformRoot->run(fn () => $this->subjects->find($subjectId));

        if ($subject !== null) {
            $this->current->refreshSubject($subject);
        }

        $this->recordSignIn($member);

        return true;
    }

    /**
     * Put the sign-in on the account's activity chain.
     *
     * Recorded HERE rather than at each door precisely because establish() is the one
     * place a session is created: password, the MFA challenge, a passkey ceremony, a
     * magic link and an SSO assertion all land on it, and a sixth way in added later
     * gets the record for free instead of being remembered about.
     *
     * This plane owns environments, API keys and billing, and until this existed the
     * act of getting in left nothing behind — the administration it enables was
     * audited, but not the entry. `last_login_at` is a single overwritten column, so a
     * takeover looked exactly like the owner's own next visit.
     *
     * Failure is swallowed: the audit chain serialises appends on an anchor row, and an
     * audit backend that is down or contended must not be able to lock every member out
     * of their own console. Observation, not gate.
     */
    private function recordSignIn(AccountMember $member): void
    {
        try {
            // IN THE PLATFORM ROOT, because an audit entry is environment-owned and the
            // account chain lives in exactly one environment. Written under whatever scope
            // the caller happened to be standing in, the same account's sign-ins land in
            // different environments depending on which door wrote them — and the console
            // reads under one, so the page would show some of them and silently omit the
            // rest. {@see PlatformAuth::recordAccountSignIn()} pins it the same way for
            // the same reason; the two must agree or the split is between doors instead of
            // between requests.
            $this->platformRoot->run(function () use ($member): bool {
                $this->activity->record(
                    $member->account_id,
                    'account.signed_in',
                    $member->id,
                    targetType: 'account_member',
                    targetId: $member->id,
                    request: request(),
                );

                return true;
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function check(): bool
    {
        return $this->current() !== null;
    }

    /** The MEMBER id behind this session, or null — never an identity, always a lookup. */
    public function id(): ?string
    {
        return $this->current()?->id;
    }

    /**
     * The account member the signed-in subject is, or null.
     *
     * Resolved from the ONE session on every call rather than read out of session state,
     * which is what makes "which account" impossible to get wrong: `account_members`
     * has a unique `subject_id`, so there is exactly one answer and nothing in the
     * browser can name a different one.
     *
     * Every standing re-check the member session carried is still made here, and for the
     * same reasons: a member suspended after they signed in — OR whose whole account was
     * suspended — loses access on the very next request, not at their next sign-in.
     */
    public function current(): ?AccountMember
    {
        if (! $this->current->check()) {
            return null;
        }

        $member = $this->findMemberBySubject($this->current->id());

        if ($member === null || ! $member->isActive()) {
            return null;
        }

        return ($member->account?->isActive() ?? false) ? $member : null;
    }

    /**
     * The member row for a platform-root subject.
     *
     * In the root's scope because the member's ACCOUNT and its organization are read
     * through the row, and those are environment-owned: under a tenant's ambient scope
     * the relations answer with nothing, which reads as "suspended account" and locks a
     * member out of their own console.
     */
    private function findMemberBySubject(string $subjectId): ?AccountMember
    {
        if ($subjectId === '') {
            return null;
        }

        if ($this->memoSubjectId === $subjectId) {
            return $this->memoMember;
        }

        $this->memoMember = $this->platformRoot->run(
            fn (): ?AccountMember => $this->members->findBySubject($subjectId),
        );
        $this->memoSubjectId = $subjectId;

        return $this->memoMember;
    }

    /**
     * Forget the memoised resolution — for the two moments the session changes under us.
     *
     * Not a general-purpose reset: the memo is keyed on the subject id, so a DIFFERENT
     * person is already handled. What is not is the SAME person whose row this request
     * just wrote — establish() stamps `last_login_at`, and logout ends the session
     * entirely.
     */
    private function forgetMemo(): void
    {
        $this->memoSubjectId = null;
        $this->memoMember = null;
    }
}
