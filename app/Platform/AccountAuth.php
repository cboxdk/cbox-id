<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\AttemptOutcome;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;
use Throwable;

/**
 * The account plane's view of the ONE session — the customer's buyer/admin plane, the
 * "workspace console".
 *
 * There is no account-member session any more, and this class is what is left once that
 * idea is removed. An account member is an ordinary subject in the platform-root
 * environment ({@see docs/core-concepts/unified-account-identity.md}); the member row says
 * WHICH ACCOUNT that subject belongs to and with what {@see AccountRole}. That is a
 * lookup, not an identity, so it has no business being session state: `account_members`
 * has a UNIQUE `subject_id`, so "which account" has exactly one answer and the session
 * cannot hold a different one.
 *
 * Three stores for one person is what this replaces, and every seam between them was a
 * bug. An operator signed in and was bounced back to the sign-in forever because the door
 * wrote a member session and the gate asked for a subject one. `attempt()` grew an
 * operator branch so somebody with no membership could get in at all. The console's gate
 * grew an operator clause to let them land. {@see Console\ConsoleScope} asked all three
 * stores in turn. None of those were separate problems.
 *
 * WHAT LOGS A MEMBER OUT EVERYWHERE. It used to be `session_version` on the member row,
 * re-checked here on every resolve. That check is gone because the thing it guarded is
 * gone — the browser holds a subject session now, which a stamp on the member row cannot
 * reach. Its replacement is stronger and lives at the right altitude:
 * `DatabaseAccountMembers::resetPassword()` revokes the subject's sessions, so the next
 * request finds no session at all rather than a session it declines to honour. The stamp
 * itself stays: it is also what makes a reset LINK single-use.
 *
 * There is deliberately NO "current environment" session state here: environments are
 * resolved statelessly from the request host ({slug}.base_domain or a custom domain), so
 * the workspace root is a pure launchpad that links OUT to each environment's own domain.
 */
final class AccountAuth
{
    /**
     * A password verified but a second factor is still outstanding. Holds only the
     * member id and grants NO console access on its own: the gate requires a full
     * session, and a full session is a SUBJECT session that only {@see establish()} mints.
     */
    public const PENDING_KEY = 'cbox.account_member_pending';

    public function __construct(
        private readonly PlatformRoot $platformRoot,
        private readonly PlatformAuth $platformAuth,
        private readonly AccountMembers $members,
        private readonly AccountMemberMfa $mfa,
        private readonly MemberCredentialGate $gate,
        private readonly AccountActivity $activity,
        private readonly CurrentUser $current,
        private readonly Subjects $subjects,
    ) {}

    /**
     * Verify credentials. Returns:
     *  - 'invalid' for a wrong password or a suspended member (never authenticates),
     *  - 'mfa'     when the password is right but a confirmed second factor is
     *              required — NO session is started, only a short-lived pending marker,
     *  - 'ok'      when the password is right and no second factor is enrolled — the
     *              full session is established immediately.
     *
     * The door authenticates a PERSON. Whether they hold an account membership, operator
     * authority, both or neither is not its business — it used to be, through an
     * `attemptOperator()` branch that existed only because a successful sign-in wrote a
     * MEMBER session and an operator has no member row to write. The session is the
     * subject's either way now, so the branch has nothing left to do: a member goes
     * through the member-specific gates below, and everyone else goes straight to the
     * subject door, which is the same {@see PlatformAuth::attemptPassword()} the tenant
     * plane uses.
     */
    public function attempt(Request $request, string $email, string $password): AttemptOutcome
    {
        $member = $this->members->findByEmail($email);

        // Not a member of any account — a platform operator, or nobody. The subject door
        // answers, and it pays its own constant-cost dummy verify on the miss path, so an
        // unknown address is no faster here than a wrong password (no enumeration oracle).
        // Run inside the platform root: this host's ambient scope is that environment only
        // by coincidence of configuration, and the subject we are authenticating lives
        // there by construction.
        if ($member === null) {
            $outcome = $this->platformRoot->run(
                fn (): AttemptOutcome => $this->platformAuth->attemptPassword($request, $email, $password),
            );

            return $outcome ?? AttemptOutcome::Invalid;
        }

        // The lockout is asked BEFORE the credential, or a locked account still answers
        // differently for a right guess than for a wrong one.
        if ($this->gate->isLockedOut($member)) {
            return AttemptOutcome::Invalid;
        }

        if (! $this->members->verifyPassword($member->id, $password)) {
            $this->gate->recordFailure($member);

            return AttemptOutcome::Invalid;
        }

        // The credential verified against the SUBJECT. The rules that govern whether a
        // verified password is still a way in live in one place both doors ask — checked
        // AFTER the credential so a refusal reveals nothing about whether it was right.
        if (! $this->gate->admits($member)) {
            return AttemptOutcome::Invalid;
        }

        // …and there has to be a live account to sign in TO. The console gate refuses a
        // suspended account on the very next request, which is what makes a suspension
        // take effect on sessions that already exist — but a door that still admitted
        // them would turn that into a loop: sign in, be refused, be sent back to the
        // door, sign in again. Asked here so the refusal happens once, at the point a
        // person can understand it.
        if (! ($member->account?->isActive() ?? false)) {
            return AttemptOutcome::Invalid;
        }

        $this->gate->clearFailures($member);

        if ($this->mfa->hasConfirmedTotp($member->id)) {
            session()->put(self::PENDING_KEY, $member->id);

            return AttemptOutcome::Mfa;
        }

        return $this->establish($member->id) ? AttemptOutcome::Ok : AttemptOutcome::Invalid;
    }

    /**
     * The member id held pending a second factor, or null. Never grants access —
     * only a full session, resolved by {@see current()}, does.
     */
    public function pendingMemberId(): ?string
    {
        $id = session()->get(self::PENDING_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Admit an ALREADY-AUTHENTICATED platform-root subject to the workspace — the landing
     * point for the sign-in methods the account plane inherits by being ordinary subjects:
     * an SSO assertion from the account's own connection, or a magic link.
     *
     * Takes the framework {@see Session} the redemption or assertion produced, so the
     * session this mints records how the person ACTUALLY authenticated rather than a
     * guess: `amr` travels from the door that verified them.
     *
     * Deny-by-default in both directions. A subject that carries no account membership is
     * simply not a workspace sign-in ({@see AttemptOutcome::Invalid}), so authenticating
     * as any tenant user never opens the account console. And a member who has enrolled a
     * second factor on this plane is still held at the challenge: a new way in must not
     * be a way AROUND the factor they added.
     */
    public function adoptSubject(Session $session): AttemptOutcome
    {
        $member = $this->findMemberBySubject($session->user_id);

        if ($member === null || ! $member->isActive() || ! ($member->account?->isActive() ?? false)) {
            return AttemptOutcome::Invalid;
        }

        if ($this->mfa->hasConfirmedTotp($member->id)) {
            session()->put(self::PENDING_KEY, $member->id);

            return AttemptOutcome::Mfa;
        }

        return $this->establish($member->id, self::methods($session)) ? AttemptOutcome::Ok : AttemptOutcome::Invalid;
    }

    /**
     * The authentication methods recorded on a framework session, narrowed to a list.
     *
     * `amr` is a JSON column, so what the cast hands back is a map rather than a list —
     * the session store is a serialization edge, and re-narrowing at it is the price of
     * that. Nothing is guessed at: every downstream reading of `amr` treats a missing
     * factor as absent, so a list that lost something fails closed.
     *
     * @return list<string>
     */
    private static function methods(Session $session): array
    {
        return array_values(array_filter(
            $session->amr,
            static fn (string $method): bool => $method !== '',
        ));
    }

    /**
     * Establish the session for a member — the single place a workspace sign-in creates
     * session state, so the password door, the MFA challenge, a passkey ceremony, an
     * invitation, a reset and a magic link can never diverge.
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

        // The pending marker is spent whether or not a factor was involved: a full
        // session must never leave a redeemable second-factor handle behind it in the
        // (data-preserving) session. establish() below drops the step-up windows for the
        // same reason, and drops them for every door at once.
        session()->forget(self::PENDING_KEY);

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
            $this->activity->record(
                $member->account_id,
                'account.signed_in',
                $member->id,
                targetType: 'account_member',
                targetId: $member->id,
                request: request(),
            );
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
     * Whether the signed-in person holds a membership that no longer admits them.
     *
     * Deliberately NOT `current() === null`, which is also the answer for somebody who
     * has no account at all — a platform operator running the deployment they do not buy.
     * Only the first is a refusal. The second is a person whose console simply contains
     * no account pages, and conflating the two is what used to shut operators out of the
     * one console they are for.
     *
     * The standing this asks about — member suspended, account suspended — used to be
     * asked by the console gate through the member SESSION, on every request. It still is
     * asked on every request; it just cannot be asked of a session any more, because the
     * session no longer knows which account it belongs to. Nothing about "a suspension
     * takes effect on the next request rather than the next sign-in" changes.
     */
    public function membershipLapsed(): bool
    {
        if (! $this->current->check()) {
            return false;
        }

        $member = $this->findMemberBySubject($this->current->id());

        return $member !== null
            && (! $member->isActive() || ! ($member->account?->isActive() ?? false));
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

        return $this->platformRoot->run(
            fn (): ?AccountMember => $this->members->findBySubject($subjectId),
        );
    }

    public function logout(Request $request): void
    {
        session()->forget(self::PENDING_KEY);

        // Through PlatformAuth, because the session being ended is a subject session:
        // it revokes the framework row and clears the held-account SET, neither of which
        // forgetting a key would touch — and an account left in that set is an account
        // the browser can switch back into without re-authenticating.
        $this->platformAuth->logoutAll($request);
    }
}
