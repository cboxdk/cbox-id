<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Models\AccountMember;
use Illuminate\Http\Request;

/**
 * Session bridge for an ACCOUNT member administering a tenant ENVIRONMENT — the
 * fourth plane. It is how the control-plane-identity model holds: the tenant admin
 * is an account-layer identity, NOT a subject inside the environment. The session is
 * established only by redeeming a platform-signed handoff (or by "sign in as admin",
 * which authenticates against the account layer), never by a subject login.
 *
 * WATERTIGHT: the session is bound to ONE environment id and re-checked against the
 * request's host-resolved environment on every resolve. A session minted for env A
 * therefore authenticates on env A's host ONLY — even if the session cookie is
 * shared across `*.cboxid.com`, it grants nothing on env B's host (bound env ≠ host
 * env → null). Membership access to the environment is re-verified too, so revoking
 * a member's access kills their admin session on the next request.
 */
final class EnvironmentAdminAuth
{
    /** The administering account member's id. */
    public const SESSION_KEY = 'cbox.env_admin_member';

    /** The environment id this admin session is bound to (the anti-bleed anchor). */
    public const ENV_KEY = 'cbox.env_admin_env';

    public function __construct(
        private readonly AccountMembers $members,
        private readonly EnvironmentContext $environments,
    ) {}

    /**
     * Establish an environment-admin session for a member on a specific environment.
     * The single place this session is created — the handoff and the admin-login
     * paths can never diverge.
     */
    public function establish(string $memberId, string $environmentId): void
    {
        session()->put(self::SESSION_KEY, $memberId);
        session()->put(self::ENV_KEY, $environmentId);
        session()->regenerate();
    }

    /**
     * The account member administering the CURRENT (host-resolved) environment, or
     * null. Every guard consults this, never the session keys directly.
     */
    public function current(): ?AccountMember
    {
        $memberId = session()->get(self::SESSION_KEY);
        $boundEnv = session()->get(self::ENV_KEY);
        $hostEnv = $this->environments->current()?->environmentKey();

        // Anti-bleed: the session's environment must be the one this host resolves to.
        if (! is_string($memberId) || $memberId === ''
            || ! is_string($boundEnv) || $boundEnv === ''
            || $hostEnv === null || $boundEnv !== $hostEnv) {
            return null;
        }

        $member = $this->members->find($memberId);

        if ($member === null || ! $member->isActive() || ! ($member->account?->isActive() ?? false)) {
            return null;
        }

        // Capability, not just reachability: administering an environment's control
        // plane is an owner/admin/developer power (AccountRole::canManageEnvironments).
        // A viewer or billing member may be ABLE TO REACH an environment
        // (all_environments defaults true on invite) but must never administer it —
        // "accessible" is not "administrable". This is the single chokepoint every
        // guard consults, so the check holds for both the handoff and admin-login paths.
        if (! $member->role->canManageEnvironments()) {
            return null;
        }

        // Access is re-verified per request: a member whose access to THIS
        // environment was revoked loses the admin session immediately.
        if (! in_array($hostEnv, $this->members->accessibleEnvironmentIds($member), true)) {
            return null;
        }

        return $member;
    }

    public function check(): bool
    {
        return $this->current() !== null;
    }

    /** The environment id the current admin session is bound to, or null. */
    public function environmentId(): ?string
    {
        $env = session()->get(self::ENV_KEY);

        return is_string($env) && $env !== '' ? $env : null;
    }

    public function logout(Request $request): void
    {
        session()->forget([self::SESSION_KEY, self::ENV_KEY]);
        session()->invalidate();
        session()->regenerateToken();
    }
}
