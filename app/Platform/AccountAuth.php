<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\AttemptOutcome;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Models\AccountMember;
use Illuminate\Http\Request;

/**
 * Session bridge for account members — the customer's buyer/admin plane, the
 * "workspace console". This is a THIRD world, distinct from both {@see PlatformAuth}
 * (org-scoped end-users, who authenticate INTO an environment) and
 * {@see OperatorAuth} (Cbox staff, above every account). An account member signs in
 * once at the platform root and administers the environments their account owns.
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

        if ($this->mfa->hasConfirmedTotp($member->id)) {
            session()->put(self::PENDING_KEY, $member->id);

            return AttemptOutcome::Mfa;
        }

        $this->establish($member->id);

        return AttemptOutcome::Ok;
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
