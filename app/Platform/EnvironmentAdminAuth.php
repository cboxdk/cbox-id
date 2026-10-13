<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
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
 * there. An admin session is an ORDINARY platform-root session ROW, minted by
 * {@see PlatformAuth}, so revoking a person's sessions (a password reset does) ends their
 * admin console too, which a session assembled out of a raw id could never do.
 *
 * TWO CONTEXTS, ONE COOKIE. On a tenant's host the browser session also carries the
 * tenant's own END-USER context: its hosted sign-in, its My account, its SSO session for
 * the tenant's apps — {@see PlatformAuth}'s held accounts and active key. The admin context
 * keeps its row under a key of its own ({@see SESSION_KEY}), so the two never overwrite
 * each other, and signing out of one leaves the other signed in: an administrator signing
 * out of the console does not sign the tenant's end user out, and an end user signing out
 * (My account, or an app's RP-initiated logout) does not close the console.
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

    /**
     * The platform-root session row behind an environment console on a tenant's host — the
     * ADMIN context, kept apart from the end-user context's {@see PlatformAuth::SESSION_KEY}.
     * (On the platform root's own console the admin context IS the root's session, and that
     * key is the one read.)
     */
    public const SESSION_KEY = 'cbox.env_admin_session';

    /**
     * Everything the admin context holds in the browser session — what its sign-out ends,
     * and what an end user's sign-out on the same host carries over untouched.
     */
    public const CONTEXT_KEYS = [self::SESSION_KEY, self::ENV_KEY, EnvironmentSudo::SESSION_KEY];

    public function __construct(
        private readonly Memberships $memberships,
        private readonly Organizations $organizations,
        private readonly EnvironmentContext $environments,
        private readonly Subjects $subjects,
        private readonly SessionManager $sessions,
        private readonly PlatformRoot $platformRoot,
        private readonly PlatformAuth $platformAuth,
        private readonly PlatformOperators $operators,
    ) {}

    /**
     * Whether this request is the PLATFORM ROOT's environment console — set by the console's
     * own gate ({@see AuthenticateEnvironmentAdmin}) when `/admin/*` is asked for on the
     * root's own host, and never anywhere else.
     *
     * A per-request fact rather than something derived from the session, and that is the
     * safety of it: an operator's ordinary root session is the very same session on every
     * page of the workspace console and of platform admin, where they must stay a SUBJECT
     * — an operator, a workspace owner — rather than become an environment administrator
     * because of who they are. Only the environment console's own routes say "this is the
     * environment plane"; everywhere else this stays false and {@see check()} answers for
     * the tenant membership alone, exactly as before.
     *
     * Kept on the REQUEST, not on this object: a container that outlives a request (Octane,
     * a test client making several) must not carry "this is the root's console" into the
     * next one, where it would turn a workspace page into an environment one.
     */
    public const ROOT_CONSOLE = 'cbox.env_admin_root_console';

    private ?string $operatorMemoKey = null;

    private ?PlatformOperator $operatorMemo = null;

    /**
     * Mark this request as the platform root's environment console. Called by the gate,
     * after the plane middleware has established that the host IS the root's own.
     */
    public function enterRootConsole(): void
    {
        request()->attributes->set(self::ROOT_CONSOLE, true);
        $this->memoKey = null;
        $this->memoMembership = null;
        $this->operatorMemoKey = null;
        $this->operatorMemo = null;
    }

    /** Whether this request is the platform root's environment console. */
    public function onRootConsole(): bool
    {
        return request()->attributes->get(self::ROOT_CONSOLE) === true;
    }

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
        $session = $this->platformRoot->run(
            fn (): Session => $this->platformAuth->startDetached(request(), $subjectId, $amr),
        );

        if (! $session instanceof Session) {
            return;
        }

        $this->bind($session->id, $environmentId);
    }

    /**
     * Put an admin session that already exists back in place — the administrator coming
     * back from a support session ({@see Impersonation::exit()}). The caller has checked
     * the row is live and theirs.
     */
    public function resume(string $sessionId, string $environmentId): void
    {
        $this->bind($sessionId, $environmentId);
    }

    /**
     * What the admin context holds in the browser session right now, key by key — to carry
     * it across something that resets the END-USER context on the same host.
     *
     * Read one key at a time: the keys are dotted, and the session stores them nested, so
     * `session()->only()` would find none of them.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $held = [];

        foreach (self::CONTEXT_KEYS as $key) {
            if (session()->has($key)) {
                $held[$key] = session()->get($key);
            }
        }

        return $held;
    }

    /** The admin context's session row on this request, or null. */
    public function sessionId(): ?string
    {
        $id = session()->get($this->onRootConsole() ? PlatformAuth::SESSION_KEY : self::SESSION_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function bind(string $sessionId, string $environmentId): void
    {
        // A step-up attests to the session it was confirmed in, and this is a new one.
        // Only the ADMIN context's step-up: the end user's on this host is theirs.
        session()->forget(EnvironmentSudo::SESSION_KEY);

        // A privilege transition: a fresh id, so a session id planted beforehand is not the
        // one that ends up administering the environment.
        session()->regenerate();

        // The identity first and the anchor after it: an anchor that briefly stood alone is
        // the state this class exists to never be in.
        session()->put(self::SESSION_KEY, $sessionId);
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
        // The root environment belongs to no workspace: on its console a membership grants
        // nothing, whoever holds it and whatever it reaches. Operators are its
        // administrators ({@see rootOperator()}).
        if ($this->onRootConsole()) {
            return null;
        }

        $sessionId = session()->get(self::SESSION_KEY);
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

    /**
     * The platform OPERATOR administering the root environment's console on this request,
     * or null — always null anywhere but that console.
     *
     * The same live checks the tenant path makes, re-read per request: the session row is
     * still active, the subject behind it is still admitted, and the operator record behind
     * the subject is still ACTIVE (the framework lookup refuses a suspended one). Asked in
     * the platform root, where the subject, its session and the operator all live — which
     * on the root's own host is also the ambient environment, so this crosses nothing.
     *
     * NOT a workspace owner, NOT an organization admin of the root: the root environment is
     * how every operator and every customer signs in, and a customer's owner configuring it
     * would be configuring sign-in for every other customer's owner.
     */
    public function rootOperator(): ?PlatformOperator
    {
        if (! $this->onRootConsole()) {
            return null;
        }

        $sessionId = session()->get(PlatformAuth::SESSION_KEY);
        $hostEnv = $this->environments->current()?->environmentKey();
        $key = (is_string($sessionId) ? $sessionId : '')."\0".($hostEnv ?? '');

        if ($this->operatorMemoKey === $key) {
            return $this->operatorMemo;
        }

        $this->operatorMemoKey = $key;

        return $this->operatorMemo = $this->resolveRootOperator($sessionId, $hostEnv);
    }

    /** Whether this request is administered by a platform operator on the root's console. */
    public function admitsRootOperator(): bool
    {
        return $this->rootOperator() !== null;
    }

    /**
     * The person signed in on the root console, whether or not they may administer it — so
     * the gate can tell "sign in first" from "this is not yours".
     */
    public function signedInRootSubject(): ?string
    {
        $sessionId = session()->get(PlatformAuth::SESSION_KEY);

        return is_string($sessionId) && $sessionId !== '' ? $this->liveSubject($sessionId) : null;
    }

    /**
     * The WORKSPACE whose membership opened this console — the organization a tenant
     * environment's administrator acts for, where the workspace console records their acts.
     * Null on the root environment's console, which no workspace owns.
     */
    public function workspaceId(): ?string
    {
        $organizationId = $this->membership()?->organization_id;

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }

    /** The platform-root subject id administering here, or null. */
    public function subjectId(): ?string
    {
        $subjectId = $this->membership()->user_id ?? $this->rootOperator()?->subject_id;

        return is_string($subjectId) && $subjectId !== '' ? $subjectId : null;
    }

    private function resolveRootOperator(mixed $sessionId, ?string $hostEnv): ?PlatformOperator
    {
        $root = $this->platformRoot->environment()?->environmentKey();

        if (! is_string($sessionId) || $sessionId === '' || $hostEnv === null || $root === null || $hostEnv !== $root) {
            return null;
        }

        $subjectId = $this->liveSubject($sessionId);

        if ($subjectId === null) {
            return null;
        }

        return $this->platformRoot->run(fn (): ?PlatformOperator => $this->operators->findBySubject($subjectId));
    }

    /**
     * The subject behind a live session in the platform root, or null — the session row
     * active, the subject still admitted. Shared by both kinds of administrator.
     */
    private function liveSubject(string $sessionId): ?string
    {
        $subjectId = $this->platformRoot->run(function () use ($sessionId): ?string {
            $session = $this->sessions->active($sessionId);

            if ($session === null) {
                return null;
            }

            $subject = $this->subjects->find($session->user_id);

            if ($subject === null) {
                return null;
            }

            return ($subject->admitsSignIn() ?? $this->subjects->isActive($session->user_id))
                ? $session->user_id
                : null;
        });

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
        // The subject is the credential of record, so a deactivated one (removed member,
        // unaccepted invitation) must lose the admin session immediately. Standing is read
        // off the row already loaded when the resolver says ({@see Subject::admitsSignIn()}).
        $subjectId = $this->liveSubject($sessionId);

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

    /** Whether ANYBODY administers this environment on this request — either kind. */
    public function check(): bool
    {
        return $this->membership() !== null || $this->rootOperator() !== null;
    }

    /**
     * The environment the current admin session administers, or null: the session's anchor
     * on a tenant's host, the root environment itself on the root's console.
     */
    public function environmentId(): ?string
    {
        if ($this->rootOperator() !== null) {
            return $this->environments->current()?->environmentKey();
        }

        $env = session()->get(self::ENV_KEY);

        return is_string($env) && $env !== '' ? $env : null;
    }

    /**
     * Sign out of the ADMIN context on a tenant's host — and of everything that sign-in
     * reached: the console's row, the account-host sessions it was handed off from, and
     * every other console handed off from those ({@see SignInLineage}). Without the account
     * host, the console's gate sent the person to the account host's open-environment door,
     * which minted a handoff straight back in.
     *
     * The END-USER context on this host is left exactly as it was: the tenant's own people
     * signed in here are another context, and their keys are not touched.
     */
    public function logout(Request $request): void
    {
        $sessionId = session()->get(self::SESSION_KEY);

        if (is_string($sessionId) && $sessionId !== '') {
            $lineage = app(SignInLineage::class);
            $lineage->revoke($lineage->family([$sessionId]));
        }

        session()->forget(self::CONTEXT_KEYS);
        $this->memoKey = null;
        $this->memoMembership = null;
        $this->operatorMemoKey = null;
        $this->operatorMemo = null;

        // A fresh id and token for what is left — the end-user context, if there is one.
        session()->migrate(true);
        session()->regenerateToken();
    }
}
