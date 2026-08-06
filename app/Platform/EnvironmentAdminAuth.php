<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;

/**
 * A CONTROL-PLANE identity administering a tenant ENVIRONMENT. The admin is a subject in
 * the PLATFORM-ROOT environment ("tenant 1") holding an account membership — never a
 * subject inside the environment being administered. The session is established only by
 * redeeming a platform-signed handoff (or by "sign in as admin", which authenticates the
 * same control-plane identity), never by a subject login on this environment.
 *
 * THERE IS NO SEPARATE ADMIN IDENTITY. This used to hold the administering subject's id
 * under a key of its own, which was a second place claiming to answer "who is this" — the
 * same duplication the account plane had, and the same one that produced sign-in loops
 * there. An admin session is now the ORDINARY subject session {@see PlatformAuth} mints,
 * so revoking a person's sessions (a password reset does) ends their admin console too,
 * which a session assembled out of a raw id could never do.
 *
 * WHAT IS LEFT IS AN ANCHOR, NOT AN IDENTITY. {@see ENV_KEY} names the ONE environment
 * this session administers, and it is re-checked against the request's host-resolved
 * environment on every resolve. That is not duplication and it is not a lookup that could
 * replace it: the session cookie is shared across `*.cboxid.com`, so without the anchor a
 * session minted on env A's host would answer on env B's. It is the same shape as the
 * organization picker in {@see Console\ConsoleScope} and the operator's target
 * environment — a SELECTION, which happens to carry a security property.
 *
 * Membership access is re-verified per request as well, so the anchor is defence in depth
 * rather than the access decision. Both hold: revoking a member's access to an environment
 * kills their admin session on the next request, AND a session anchored elsewhere is worth
 * nothing here whoever it belongs to.
 *
 * WHY THIS RESOLVES THE SESSION ITSELF rather than reading {@see CurrentUser}. The console
 * is served on a TENANT host, so the ambient environment is the tenant's — and
 * `auth_sessions` and `users` are both environment-owned. The Authenticate middleware
 * resolves them under the ambient scope, so on this host it finds nothing and CurrentUser
 * is structurally empty. The lookup has to cross into the platform root, which is exactly
 * what this class already had to do for the subject and the membership.
 */
final class EnvironmentAdminAuth
{
    /**
     * The environment this admin session is anchored to — the anti-bleed anchor, and the
     * only thing about an admin session that is not simply "who is signed in".
     */
    public const ENV_KEY = 'cbox.env_admin_env';

    public function __construct(
        private readonly Memberships $memberships,
        private readonly Organizations $organizations,
        private readonly EnvironmentContext $environments,
        private readonly Subjects $subjects,
        private readonly SessionManager $sessions,
        private readonly PlatformRoot $platformRoot,
        private readonly PlatformAuth $platformAuth,
    ) {}

    /**
     * Per-request memo, KEYED ON THE INPUTS it was computed from. current() is consulted
     * on every request by the persistent middleware, the layout, and each component's
     * boot() — three resolutions of ~4 identity queries each, for one answer.
     *
     * The key is deliberate rather than a plain "computed once" flag: the host-resolved
     * environment can legitimately change within a request ({@see EnvironmentContext::runAs()}),
     * and the anti-bleed guarantee below is a security invariant — a memo that outlived a
     * context switch would answer for the PREVIOUS environment. Re-deriving the key each
     * call keeps the invariant exact while still collapsing the repeat calls.
     */
    private ?string $memoKey = null;

    private ?Membership $memoMembership = null;

    /**
     * Establish an environment-admin session for a platform-root subject on a specific
     * environment. The single place this session is created — the handoff and the
     * admin-login paths can never diverge.
     *
     * It mints the ORDINARY subject session, through the same
     * {@see PlatformAuth::establish()} every other door uses, and anchors it. Inside the
     * platform root because that is where the subject and its session row belong; the
     * ambient scope here is the tenant being administered.
     *
     * @param  list<string>  $amr  how the admin proved who they are — a redeemed handoff
     *                             is possession of a platform signature, not a password
     */
    public function establish(string $subjectId, string $environmentId, array $amr = ['handoff']): void
    {
        $this->platformRoot->run(function () use ($subjectId, $amr): bool {
            $this->platformAuth->establish(request(), $subjectId, $amr);

            return true;
        });

        // After establish(), which regenerates the id: regeneration preserves data, so
        // the order is cosmetic — but an anchor written before the identity would be an
        // anchor that briefly stood alone, and that is the state this class exists to
        // never be in.
        session()->put(self::ENV_KEY, $environmentId);

        // The session just changed under us; drop any memoised resolution.
        $this->memoKey = null;
        $this->memoMembership = null;
    }

    /**
     * The account membership administering the CURRENT (host-resolved) environment, or
     * null. Every guard consults this, never the session state directly. Memoised per
     * request (see {@see $memoKey}).
     */
    public function membership(): ?Membership
    {
        $sessionId = session()->get(PlatformAuth::SESSION_KEY);
        $boundEnv = session()->get(self::ENV_KEY);
        $hostEnv = $this->environments->current()?->environmentKey();

        // Memo hit only when every input is identical to the one we resolved from.
        $key = implode("\0", [
            is_string($sessionId) ? $sessionId : '',
            is_string($boundEnv) ? $boundEnv : '',
            $hostEnv ?? '',
        ]);

        if ($this->memoKey === $key) {
            return $this->memoMembership;
        }

        $this->memoMembership = $this->resolve($sessionId, $boundEnv, $hostEnv);
        $this->memoKey = $key;

        return $this->memoMembership;
    }

    /** The platform-root subject id administering here, or null. */
    public function subjectId(): ?string
    {
        $subjectId = $this->membership()?->user_id;

        return is_string($subjectId) && $subjectId !== '' ? $subjectId : null;
    }

    private function resolve(mixed $sessionId, mixed $boundEnv, ?string $hostEnv): ?Membership
    {
        // Anti-bleed: the session's anchor must be the environment this host resolves to.
        // FIRST, before any lookup: a session anchored to env A must be worth nothing on
        // env B's host regardless of who it belongs to or what they may administer.
        if (! is_string($sessionId) || $sessionId === ''
            || ! is_string($boundEnv) || $boundEnv === ''
            || $hostEnv === null || $boundEnv !== $hostEnv) {
            return null;
        }

        // The session and the identity behind it must both still be live, in the PLATFORM
        // ROOT. Resolved there explicitly: the ambient scope on this request is the tenant
        // environment being administered, and looking a control-plane subject up under a
        // tenant's scope would either find nothing or — far worse — find a same-id row
        // belonging to the tenant.
        //
        // Asking the session ROW, not just the cookie, is what a raw id in the session
        // could never do: a revoked or expired session is refused here, so revoking an
        // admin's sessions ends their console rather than merely their next sign-in.
        $subjectId = $this->platformRoot->run(function () use ($sessionId): ?string {
            $session = $this->sessions->active($sessionId);

            if ($session === null) {
                return null;
            }

            // The subject is the credential of record, so a deactivated one (removed
            // member, unaccepted invitation) must lose the admin session immediately.
            //
            // Standing is read off the row already loaded when the resolver says
            // ({@see Subject::admitsSignIn()}), and falls back to asking the contract when
            // it does not — a host-bound Subjects implementation that predates the field
            // keeps being asked rather than being assumed active. It was two identical
            // `select * from users where id = ?` before, on every environment-console page
            // and every Livewire round trip.
            $subject = $this->subjects->find($session->user_id);

            if ($subject === null) {
                return null;
            }

            return ($subject->admitsSignIn() ?? $this->subjects->isActive($session->user_id))
                ? $session->user_id
                : null;
        });

        if (! is_string($subjectId) || $subjectId === '') {
            return null;
        }

        // …and it must still carry a MEMBERSHIP of a customer organization. That is the
        // management-plane authority: the role, the organization's status and the
        // environment grants all hang off it, and it is re-read per request rather than
        // trusted from the session. It is also what keeps an ordinary TENANT subject out:
        // their session resolves in the tenant, not the root, and they hold no membership
        // there either way.
        $membership = $this->platformRoot->run(
            fn (): ?Membership => $this->memberships->forUser($subjectId)->first(),
        );

        if ($membership === null) {
            return null;
        }

        // …AND THE ORGANIZATION IT BELONGS TO MUST STILL BE LIVE. The account plane asked
        // this as `$member->account?->isActive()`, and re-pointing the resolver at the
        // membership dropped it: a customer suspended in the seconds since the handoff was
        // minted — or by an operator while the tab sat open — kept a live environment-admin
        // session on a tenant host. The host resolver refuses a suspended owner too, but
        // that answer is CACHED, which is exactly why this second check exists.
        $ownerLive = $this->platformRoot->run(
            fn (): bool => $this->organizations->find($membership->organization_id)?->status->revokesAccess() === false,
        ) === true;

        if (! $ownerLive) {
            return null;
        }

        // Capability, not just reachability: administering an environment's control plane
        // is an owner/admin/developer power. A viewer may be ABLE TO REACH an environment
        // (all_environments defaults true) but must never administer it — "accessible" is
        // not "administrable". This is the single chokepoint every guard consults, so the
        // check holds for both the handoff and admin-login paths.
        if (! OrganizationCapabilities::of($membership->role)->canManageEnvironments()) {
            return null;
        }

        // Access is re-verified per request: a member whose access to THIS environment was
        // revoked loses the admin session immediately.
        $reachable = $this->platformRoot->run(
            fn (): array => $this->memberships->accessibleEnvironmentIds(
                $membership->organization_id,
                $subjectId,
            ),
        ) ?? [];

        if (! in_array($hostEnv, $reachable, true)) {
            return null;
        }

        return $membership;
    }

    public function check(): bool
    {
        return $this->membership() !== null;
    }

    /** The environment id the current admin session is anchored to, or null. */
    public function environmentId(): ?string
    {
        $env = session()->get(self::ENV_KEY);

        return is_string($env) && $env !== '' ? $env : null;
    }

    public function logout(Request $request): void
    {
        session()->forget(self::ENV_KEY);
        $this->memoKey = null;
        $this->memoMembership = null;

        // Through PlatformAuth, because what is being ended is a subject session: it
        // revokes the framework row and clears the held-account SET, neither of which
        // invalidating the cookie alone would reach.
        $this->platformAuth->logoutAll($request);
    }
}
