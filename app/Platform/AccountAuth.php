<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\AttemptOutcome;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;

/**
 * Session bridge for account members — the customer's buyer/admin plane, the
 * "workspace console". A distinct SESSION from both {@see PlatformAuth} (a tenant's
 * end-users, who authenticate INTO their own environment) and {@see OperatorAuth} (Cbox
 * staff, above every account), but no longer a distinct CREDENTIAL: an account member is
 * an ordinary subject in the platform-root environment, and this class authenticates
 * against that subject.
 *
 * That is what removes the second identity stack. The password is verified by
 * {@see AccountMembers::verifyPassword()}, which asks the subject; the SSO mandate is
 * {@see PlatformAuth::passwordLoginAllowedFor()}, the same method the tenant door uses;
 * an administratively-issued temporary password expires here exactly as it does there.
 * None of those rules are re-implemented for this plane, so none of them can drift out
 * of step with it. See docs/core-concepts/unified-account-identity.md.
 *
 * There is deliberately NO "current environment" session state here: environments
 * are resolved statelessly from the request host ({slug}.base_domain or a custom
 * domain), so the workspace root is a pure launchpad that links OUT to each
 * environment's own domain — it never pins an environment in the session the way
 * the operator console (a single cross-env URL) must.
 */
final class AccountAuth
{
    public const SESSION_KEY = 'cbox.account_member';

    /**
     * A password verified but a second factor is still outstanding. Holds only the
     * member id and — deliberately distinct from {@see SESSION_KEY} — grants NO
     * console access on its own: the gate still requires a full session.
     */
    public const PENDING_KEY = 'cbox.account_member_pending';

    /**
     * The member's security stamp captured at sign-in. Re-checked on every resolve:
     * a password reset bumps the member's stamp, so every session carrying the old
     * value is logged out at once.
     */
    public const SESSION_VERSION_KEY = 'cbox.account_member_v';

    public function __construct(
        private readonly AccountMembers $members,
        private readonly AccountMemberMfa $mfa,
        private readonly PlatformAuth $platform,
        private readonly AdminPasswords $adminPasswords,
        private readonly PlatformRoot $platformRoot,
    ) {}

    /**
     * Verify credentials. Returns:
     *  - 'invalid' for a wrong password or a suspended member (never authenticates),
     *  - 'mfa'     when the password is right but a confirmed second factor is
     *              required — NO session is started, only a short-lived pending marker,
     *  - 'ok'      when the password is right and no second factor is enrolled — the
     *              full session is established immediately.
     */
    public function attempt(Request $request, string $email, string $password): AttemptOutcome
    {
        $member = $this->members->findByEmail($email);

        $verified = $member !== null && $this->members->verifyPassword($member->id, $password);

        // Run a dummy verify when the email is unknown so the miss path stays
        // constant-cost — an unknown email must not be measurably faster than a
        // wrong password (no enumeration timing oracle).
        if ($member === null) {
            $this->members->verifyPassword('', $password);
        }

        if (! $verified) {
            return AttemptOutcome::Invalid;
        }

        // The credential verified against the SUBJECT. The rules that govern whether a
        // verified password is still a way in are the subject plane's rules, applied
        // here through the subject plane's own code — checked AFTER the credential so a
        // refusal reveals nothing about whether the password was right.
        if (! $this->passwordSignInAllowed($member)) {
            return AttemptOutcome::Invalid;
        }

        if ($this->mfa->hasConfirmedTotp($member->id)) {
            session()->put(self::PENDING_KEY, $member->id);

            return AttemptOutcome::Mfa;
        }

        $this->establish($member->id);

        return AttemptOutcome::Ok;
    }

    /**
     * Whether a verified password is still an admissible way in for this member.
     *
     * Both gates belong to the subject plane and are asked of the subject plane:
     *
     *  - An administratively-issued temporary password stops admitting anyone once its
     *    deadline passes, even though the hash still matches — otherwise a hand-off
     *    credential lingers as a permanent second way in.
     *  - A tenant that mandates SSO means it. The account's organization lives in the
     *    platform root like any other, so its policy applies to the workspace door;
     *    without this, "require SSO" on an account could be sidestepped by signing in
     *    at the account door instead of the tenant one.
     *
     * A member with no subject is the first-install bootstrap window (nowhere for the
     * subject to live yet), and there is no policy to consult — the founder gets in.
     */
    private function passwordSignInAllowed(AccountMember $member): bool
    {
        $subjectId = $member->subject_id;

        if ($subjectId === null) {
            return true;
        }

        // Policies and memberships are environment-owned, so this must be read in the
        // platform root's scope — the environment the account's people actually live in.
        return $this->platformRoot->run(
            fn (): bool => ! $this->adminPasswords->hasExpired($subjectId)
                && $this->platform->passwordLoginAllowedFor($subjectId),
        ) === true;
    }

    /**
     * The member id held pending a second factor, or null. Never grants access —
     * only {@see current()} (SESSION_KEY + active status) does.
     */
    public function pendingMemberId(): ?string
    {
        $id = session()->get(self::PENDING_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Adopt an ALREADY-AUTHENTICATED platform-root subject into an account session — the
     * landing point for the sign-in methods the account plane inherits by being ordinary
     * subjects: an SSO assertion from the account's own connection, or a magic link.
     *
     * Deny-by-default in both directions. A subject that carries no account membership is
     * simply not a workspace sign-in ({@see AttemptOutcome::Invalid}), so authenticating
     * as any tenant user never opens the account console. And a member who has enrolled a
     * second factor on this plane is still held at the challenge: a new way in must not
     * be a way AROUND the factor they added.
     */
    public function adoptSubject(string $subjectId): AttemptOutcome
    {
        $member = $subjectId !== '' ? $this->members->findBySubject($subjectId) : null;

        if ($member === null || ! $member->isActive() || ! ($member->account?->isActive() ?? false)) {
            return AttemptOutcome::Invalid;
        }

        if ($this->mfa->hasConfirmedTotp($member->id)) {
            session()->put(self::PENDING_KEY, $member->id);

            return AttemptOutcome::Mfa;
        }

        $this->establish($member->id);

        return AttemptOutcome::Ok;
    }

    /**
     * Establish the member session — the single place session state is created, so
     * the sign-in and post-signup paths can never diverge.
     */
    public function establish(string $memberId): void
    {
        $this->members->touchLogin($memberId);

        $member = $this->members->find($memberId);

        session()->forget(self::PENDING_KEY);
        session()->put(self::SESSION_KEY, $memberId);
        session()->put(self::SESSION_VERSION_KEY, $member !== null ? $member->session_version : 0);
        session()->regenerate();
    }

    public function check(): bool
    {
        return $this->current() !== null;
    }

    public function id(): ?string
    {
        $id = session()->get(self::SESSION_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function current(): ?AccountMember
    {
        $id = $this->id();

        // Re-check on every resolve, not just at sign-in: a member suspended after
        // login — OR whose whole account was suspended — loses access immediately.
        $member = $id !== null ? $this->members->find($id) : null;

        if ($member === null || ! $member->isActive()) {
            return null;
        }

        if (! ($member->account?->isActive() ?? false)) {
            return null;
        }

        // Security-stamp check: a session whose captured version is behind the
        // member's current one (e.g. after a password reset) is dead. A legacy
        // session with no stamp reads as 0, matching the default, so nobody is
        // logged out just by deploying this.
        $stamped = session()->get(self::SESSION_VERSION_KEY);

        return (int) (is_numeric($stamped) ? $stamped : 0) === $member->session_version ? $member : null;
    }

    public function logout(Request $request): void
    {
        session()->forget([self::SESSION_KEY, self::PENDING_KEY]);
        // invalidate() (not regenerate()) so no member session data survives logout,
        // matching the operator and org-user planes.
        session()->invalidate();
        session()->regenerateToken();
    }
}
