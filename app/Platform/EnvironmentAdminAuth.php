<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;

/**
 * Session bridge for a CONTROL-PLANE identity administering a tenant ENVIRONMENT — the
 * fourth plane. The tenant admin is a subject in the PLATFORM-ROOT environment ("tenant
 * 1") holding an account membership — never a subject inside the environment being
 * administered. The session is established only by redeeming a platform-signed handoff
 * (or by "sign in as admin", which authenticates the same control-plane identity), never
 * by a subject login on this environment.
 *
 * The session carries the SUBJECT id, because the subject is the credential of record.
 * The account membership — and with it the {@see AccountRole} capability gate — is
 * re-resolved from that subject on every request, so a membership revoked (or a role
 * downgraded) after sign-in takes effect on the next request rather than at the next
 * login.
 *
 * WATERTIGHT: the session is bound to ONE environment id and re-checked against the
 * request's host-resolved environment on every resolve. A session minted for env A
 * therefore authenticates on env A's host ONLY — even if the session cookie is
 * shared across `*.cboxid.com`, it grants nothing on env B's host (bound env ≠ host
 * env → null). That property is independent of where the identity comes from and is
 * unchanged by the move to subjects. Membership access to the environment is re-verified
 * too, so revoking a member's access kills their admin session on the next request.
 */
final class EnvironmentAdminAuth
{
    /**
     * The administering PLATFORM-ROOT SUBJECT's id. Renamed with the cutover so any
     * session minted under the old (account-member-keyed) scheme reads as absent rather
     * than being reinterpreted as a subject id — a stale session must fail closed.
     */
    public const SESSION_KEY = 'cbox.env_admin_subject';

    /** The environment id this admin session is bound to (the anti-bleed anchor). */
    public const ENV_KEY = 'cbox.env_admin_env';

    public function __construct(
        private readonly AccountMembers $members,
        private readonly EnvironmentContext $environments,
        private readonly Subjects $subjects,
        private readonly PlatformRoot $platformRoot,
    ) {}

    /**
     * Per-request memo, KEYED ON THE INPUTS it was computed from. current() is consulted
     * on every request by the persistent middleware, the layout, and each component's
     * boot() — three resolutions of ~3 identity queries each, for one answer.
     *
     * The key is deliberate rather than a plain "computed once" flag: the host-resolved
     * environment can legitimately change within a request ({@see EnvironmentContext::runAs()}),
     * and the anti-bleed guarantee below is a security invariant — a memo that outlived a
     * context switch would answer for the PREVIOUS environment. Re-deriving the key each
     * call keeps the invariant exact while still collapsing the repeat calls.
     */
    private ?string $memoKey = null;

    private ?AccountMember $memoMember = null;

    /**
     * Establish an environment-admin session for a platform-root subject on a specific
     * environment. The single place this session is created — the handoff and the
     * admin-login paths can never diverge.
     */
    public function establish(string $subjectId, string $environmentId): void
    {
        session()->put(self::SESSION_KEY, $subjectId);
        session()->put(self::ENV_KEY, $environmentId);
        session()->regenerate();

        // The session just changed under us; drop any memoised resolution.
        $this->memoKey = null;
        $this->memoMember = null;
    }

    /**
     * The account membership administering the CURRENT (host-resolved) environment, or
     * null. Every guard consults this, never the session keys directly. Memoised per
     * request (see {@see $memoKey}).
     */
    public function current(): ?AccountMember
    {
        $subjectId = session()->get(self::SESSION_KEY);
        $boundEnv = session()->get(self::ENV_KEY);
        $hostEnv = $this->environments->current()?->environmentKey();

        // Memo hit only when every input is identical to the one we resolved from.
        $key = implode("\0", [
            is_string($subjectId) ? $subjectId : '',
            is_string($boundEnv) ? $boundEnv : '',
            $hostEnv ?? '',
        ]);

        if ($this->memoKey === $key) {
            return $this->memoMember;
        }

        $this->memoMember = $this->resolve($subjectId, $boundEnv, $hostEnv);
        $this->memoKey = $key;

        return $this->memoMember;
    }

    /** The platform-root subject id this admin session is keyed on, or null. */
    public function subjectId(): ?string
    {
        $id = session()->get(self::SESSION_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function resolve(mixed $subjectId, mixed $boundEnv, ?string $hostEnv): ?AccountMember
    {
        // Anti-bleed: the session's environment must be the one this host resolves to.
        // FIRST, before any lookup: a session minted for env A must be worth nothing on
        // env B's host regardless of who it belongs to or what they may administer.
        if (! is_string($subjectId) || $subjectId === ''
            || ! is_string($boundEnv) || $boundEnv === ''
            || $hostEnv === null || $boundEnv !== $hostEnv) {
            return null;
        }

        // The identity itself must still be live in the platform root. Resolved THERE,
        // explicitly: the ambient scope on this request is the tenant environment being
        // administered, and looking a control-plane subject up under a tenant's scope
        // would either find nothing or — far worse — find a same-id row belonging to the
        // tenant. The subject is the credential of record, so a deactivated one (removed
        // member, unaccepted invitation) must lose the admin session immediately.
        $subjectActive = $this->platformRoot->run(
            fn (): bool => $this->subjects->find($subjectId) !== null && $this->subjects->isActive($subjectId),
        );

        if ($subjectActive !== true) {
            return null;
        }

        // …and it must still carry an account membership. This is the account-layer
        // authority: AccountRole, account status, and the environment grants all hang
        // off it, and it is re-read per request rather than trusted from the session.
        $member = $this->members->findBySubject($subjectId);

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
