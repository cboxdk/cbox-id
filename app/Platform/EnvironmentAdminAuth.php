<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\ResolveEnvironmentFromPath;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\NativeProduct\NativeProductAccess;
use App\Platform\ServiceAccess\ProductAdmission;
use Cbox\Id\Identity\Contracts\MfaVerification;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;

/**
 * A CONTROL-PLANE identity administering a tenant ENVIRONMENT. The admin is a subject in
 * the PLATFORM-ROOT environment ("tenant 1") holding an account membership — never a
 * subject inside the environment being administered.
 *
 * THE CONSOLE LIVES ON THE APP HOST, at
 * `/orgs/{org}/projects/{project}/environments/{environment}/…` ({@see ResolveEnvironmentFromPath}).
 * The administrator's session is their ordinary app-host session ({@see PlatformAuth::SESSION_KEY});
 * the environment is the one the PATH names, which the path middleware puts on the request
 * before anything here is asked. There is no second session, no handoff and no anchor in
 * the cookie any more: the path is the selection, and the authority behind it is re-read
 * on every request — the session row, the subject, the membership, the organization's
 * standing, the native product's roles and the environment grant.
 *
 * Outside a console request every answer here is null: a page that is not an environment
 * console must never be treated as one because of who is signed in.
 */
final class EnvironmentAdminAuth
{
    /**
     * What the admin context holds in the browser session: its step-up, and nothing else.
     * The identity is the app-host session's.
     */
    public const CONTEXT_KEYS = [EnvironmentSudo::SESSION_KEY];

    /** Whether this request is a customer environment's console on the app host. */
    public const ENVIRONMENT_CONSOLE = 'cbox.env_admin_environment_console';

    public function __construct(
        private readonly Memberships $memberships,
        private readonly Organizations $organizations,
        private readonly EnvironmentContext $environments,
        private readonly Subjects $subjects,
        private readonly SessionManager $sessions,
        private readonly PlatformRoot $platformRoot,
        private readonly PlatformOperators $operators,
    ) {}

    /**
     * Whether this request is the PLATFORM ROOT's environment console — set by the path
     * middleware ({@see ResolveEnvironmentFromPath}) for `/admin/environment/…` on the app
     * host, and never anywhere else.
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
     * Mark this request as the platform root's environment console. Called by the path
     * middleware for `/admin/environment/…`, on the app host.
     */
    public function enterRootConsole(): void
    {
        request()->attributes->set(self::ROOT_CONSOLE, true);
        $this->forgetMemo();
    }

    /**
     * Mark this request as a customer environment's console. Called by the path middleware
     * once the environment in the path is the request's context.
     */
    public function enterEnvironmentConsole(): void
    {
        request()->attributes->set(self::ENVIRONMENT_CONSOLE, true);
        $this->forgetMemo();
    }

    /** Whether this request is the platform root's environment console. */
    public function onRootConsole(): bool
    {
        return request()->attributes->get(self::ROOT_CONSOLE) === true;
    }

    /** Whether this request is a customer environment's console. */
    public function onEnvironmentConsole(): bool
    {
        return request()->attributes->get(self::ENVIRONMENT_CONSOLE) === true;
    }

    /**
     * Per-request memo, KEYED ON THE INPUTS it was computed from: the session, the
     * environment the path opened, and the request's environment context. The context can
     * change within a request ({@see EnvironmentContext::runAs()}), and the answer is a
     * security decision — a memo that outlived a context switch would answer for the
     * previous environment.
     */
    private ?string $memoKey = null;

    private ?Membership $memoMembership = null;

    private function forgetMemo(): void
    {
        $this->memoKey = null;
        $this->memoMembership = null;
        $this->operatorMemoKey = null;
        $this->operatorMemo = null;
    }

    /** The administrator's session row on this request — the app host's own — or null. */
    public function sessionId(): ?string
    {
        $id = session()->get(PlatformAuth::SESSION_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The account membership administering the environment this console request opened,
     * or null. Every guard consults this. Null outside a customer environment's console.
     */
    public function membership(): ?Membership
    {
        // The root environment belongs to no organization: on its console a membership grants
        // nothing, whoever holds it. Operators are its administrators ({@see rootOperator()}).
        if (! $this->onEnvironmentConsole() || $this->onRootConsole()) {
            return null;
        }

        $sessionId = $this->sessionId();
        $pathEnv = request()->attributes->get(ResolveEnvironmentFromPath::ATTRIBUTE);
        $contextEnv = $this->environments->current()?->environmentKey();

        $key = implode("\0", [$sessionId ?? '', is_string($pathEnv) ? $pathEnv : '', $contextEnv ?? '']);

        if ($this->memoKey === $key) {
            return $this->memoMembership;
        }

        $this->memoMembership = $this->resolve($sessionId, $pathEnv, $contextEnv);
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
    public function rootOrganizationId(): ?string
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

            if (app(MfaVerification::class)->requiresVerification($session->user_id, array_values($session->amr))) {
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
        // Anti-bleed: the environment the path opened must be the request's context. FIRST,
        // before any lookup: whatever runs in another environment's scope later in this
        // request must find no administrator there.
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
        $membership = $this->platformRoot->run(function () use ($subjectId, $hostEnv): ?Membership {
            $projectId = Environment::query()->whereKey($hostEnv)->value('project_id');
            $organizationId = is_string($projectId) ? Project::query()->whereKey($projectId)->value('organization_id') : null;

            return is_string($organizationId) ? $this->memberships->of($organizationId, $subjectId) : null;
        });

        if ($membership === null || $membership->status !== MembershipStatus::Active) {
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

        // Read admission is separate from write authority. Shared membership alone
        // grants neither; the native app's live roles decide both.
        if (! app(NativeProductAccess::class)
            ->forWebSubject($subjectId, $membership->organization_id)->canReadEnvironments()) {
            return null;
        }

        // Access is re-verified per request: a member whose access to THIS environment was
        // revoked loses the admin session immediately.
        $reachable = $this->platformRoot->run(
            fn (): array => app(NativeEnvironmentAccess::class)->ids(
                $membership->organization_id,
                $subjectId,
            ),
        ) ?? [];

        if (! in_array($hostEnv, $reachable, true)) {
            return null;
        }

        app(ProductAdmission::class)->assertWebGranted($subjectId, $membership->organization_id);

        return $membership;
    }

    /** Whether this request may change the environment. */
    public function check(): bool
    {
        if ($this->rootOperator() !== null) {
            return true;
        }

        $membership = $this->membership();

        return $membership !== null && app(NativeProductAccess::class)
            ->forWebSubject($membership->user_id, $membership->organization_id)->canManageEnvironments();
    }

    /** Read-only product roles can enter without gaining any write authority. */
    public function canRead(): bool
    {
        return $this->membership() !== null || $this->rootOperator() !== null;
    }

    /**
     * The environment this console request administers, or null when nobody may: the one
     * the path named, once its administrator has been resolved for it.
     */
    public function environmentId(): ?string
    {
        if ($this->rootOperator() === null && $this->membership() === null) {
            return null;
        }

        return $this->environments->current()?->environmentKey();
    }
}
