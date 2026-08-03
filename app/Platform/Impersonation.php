<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;

/**
 * Support impersonation: a platform operator "steps into" a specific org member's
 * session for support, then exits back to the operator console.
 *
 * All state lives in ONE session marker ({@see SESSION_KEY}) so controllers,
 * middleware and views stay thin. The security model is deliberately strict:
 *
 *  - While impersonating the browser is PURELY the subject. This is the property the
 *    whole feature rests on, and what enforces it changed with the operator: it used
 *    to be enough to forget a separate `cbox.operator` key, because that key WAS the
 *    operator's authority. Authority now rides an ORDINARY session — an operator is a
 *    subject, and {@see ConsoleScope::operator()} resolves them from it — so that
 *    session, and every other account the browser was holding, is what has to be put
 *    aside. Leave any of it in place and the impersonator keeps the platform pages
 *    inside the impersonated session, which is the worst outcome this feature has.
 *    The environment ANCHOR goes with it: it is not an identity, but an admin console
 *    is a session plus an anchor, and half of one is still half a way in.
 *  - The subject session carries an `impersonation` amr, so it is never mistaken
 *    for a real login by anything that inspects how the session authenticated.
 *  - Both the start and the end are recorded on the tenant's audit trail, attributed
 *    to the acting operator ({@see ActorType::Operator}).
 *  - A forgotten impersonation self-terminates after {@see MAX_MINUTES} minutes
 *    (enforced per request by EnforceImpersonationWindow).
 *
 * The principal id in the marker is the one captured (and authorized) at start, and
 * the acting identity restored on exit is resolved FRESH from it — nothing the subject
 * did while impersonated can influence who comes back. The marker itself lives in the
 * server-side session store, so it is not something a browser can author; even so
 * nothing in it is trusted as an authorization (see {@see restoreOperator()}).
 */
final class Impersonation
{
    /**
     * The impersonation marker. Distinct from every other session key so it can be
     * checked in isolation (the banner, the exit guard, the time-box) without
     * touching the subject or operator session bridges.
     */
    public const SESSION_KEY = 'cbox.impersonation';

    /** Hard ceiling on an impersonation session's lifetime, in minutes. */
    public const MAX_MINUTES = 30;

    public function __construct(
        private readonly PlatformAuth $platformAuth,
        private readonly SessionManager $sessions,
        private readonly AuditLog $audit,
        private readonly AccountMembers $members,
        private readonly PlatformOperators $operators,
        private readonly PlatformRoot $platformRoot,
        private readonly OperatorEnvironment $target,
    ) {}

    /**
     * Step into the subject's session. The caller has ALREADY authorized this
     * (the operator is real, and $subjectId is a real member of $orgId in the
     * operator's currently-pinned plane) and captured a justification $reason —
     * impersonation is privileged access, so why it happened is recorded up front.
     */
    public function start(Request $request, string $operatorId, string $subjectId, string $orgId, string $reason): void
    {
        // The step-up does not travel across an impersonation boundary in either
        // direction. Entering, the confirmation belongs to the operator and not to the
        // person whose session this becomes; leaving, it belongs to a session that no
        // longer exists. Either way it attests to something that has stopped being true.
        session()->forget([Sudo::SESSION_KEY, WorkspaceSudo::SESSION_KEY]);

        // Capture the operator's currently-targeted plane so exit can re-pin it —
        // it is the plane the org lives in (the operator reached the member in-plane).
        $env = $this->target->slug();

        // Become PURELY the subject. The operator's authority rides whichever session they
        // signed in with, so BOTH are suspended; see suspendPrincipal(). What survives is
        // the operator id recorded below — restored, and only that, on exit.
        $suspended = $this->suspendPrincipal($request);

        $request->session()->put(self::SESSION_KEY, (new ImpersonationMarker(
            actorType: ActorType::Operator,
            operator: $operatorId,
            subject: $subjectId,
            organizationId: $orgId,
            environmentKey: $env,
            reason: $reason,
            startedAt: now()->getTimestamp(),
            suspended: $suspended,
        ))->toSession());

        // Start the subject session with an `impersonation` amr so it is never
        // mistaken for a real login. establish() regenerates the session id; the
        // marker (session data) survives the regeneration.
        $this->platformAuth->establish($request, $subjectId, ['impersonation'], $orgId);

        $this->audit->record(new AuditEvent(
            action: 'platform.impersonation_started',
            actorType: ActorType::Operator,
            actorId: $operatorId,
            organizationId: $orgId,
            targetType: 'user',
            targetId: $subjectId,
            context: ['reason' => $reason],
            ip: $request->ip(),
        ));
    }

    /**
     * Step into a subject's session as an ENVIRONMENT ADMIN (an account member
     * administering this environment), rather than a platform operator.
     *
     * Same strict model as {@see start()}: the caller has already authorized this
     * (the subject is a real member of $orgId within the env-admin's environment)
     * and captured a justification. Here the environment BINDING is what is put aside —
     * so the /admin control plane is unreachable while impersonating — and the acting
     * principal is recorded as an {@see ActorType::AccountMember}. That binding is what
     * gets restored on exit.
     */
    public function startAsAccountMember(Request $request, string $memberId, string $subjectId, string $orgId, string $reason): void
    {
        // The step-up does not travel across an impersonation boundary in either
        // direction. Entering, the confirmation belongs to the operator and not to the
        // person whose session this becomes; leaving, it belongs to a session that no
        // longer exists. Either way it attests to something that has stopped being true.
        session()->forget([Sudo::SESSION_KEY, WorkspaceSudo::SESSION_KEY]);

        // An env-admin session is anchored to exactly one environment; capture the anchor
        // so exit can restore it, and drop it so /admin is unreachable until then.
        // Dropping the identity is not enough on its own: the anchor is half of what makes
        // an admin session worth anything, and an admin who came back UNBOUND would keep
        // the console with the anti-bleed check silently satisfied by nothing.
        $env = $request->session()->get(EnvironmentAdminAuth::ENV_KEY);

        // …and put aside the identity itself, for the same reason the operator branch
        // does: an env admin is an ordinary signed-in subject, and left in place they
        // would still be that person inside the impersonated session, one switch away.
        $suspended = $this->suspendPrincipal($request);

        $request->session()->put(self::SESSION_KEY, (new ImpersonationMarker(
            actorType: ActorType::AccountMember,
            operator: $memberId,
            subject: $subjectId,
            organizationId: $orgId,
            environmentKey: is_string($env) && $env !== '' ? $env : null,
            reason: $reason,
            startedAt: now()->getTimestamp(),
            suspended: $suspended,
        ))->toSession());

        $this->platformAuth->establish($request, $subjectId, ['impersonation'], $orgId);

        $this->audit->record(new AuditEvent(
            action: 'account.impersonation_started',
            actorType: ActorType::AccountMember,
            actorId: $memberId,
            organizationId: $orgId,
            targetType: 'user',
            targetId: $subjectId,
            context: ['reason' => $reason],
            ip: $request->ip(),
        ));
    }

    /**
     * Leave impersonation: end the subject session and restore the operator. A no-op
     * when there is no active marker (e.g. a double-submit of the exit form).
     */
    public function exit(Request $request): void
    {
        // The step-up does not travel across an impersonation boundary in either
        // direction. Entering, the confirmation belongs to the operator and not to the
        // person whose session this becomes; leaving, it belongs to a session that no
        // longer exists. Either way it attests to something that has stopped being true.
        session()->forget([Sudo::SESSION_KEY, WorkspaceSudo::SESSION_KEY]);

        $marker = $this->active();

        if ($marker === null) {
            return;
        }

        $isAccountMember = $marker->isAccountMember();

        $this->audit->record(new AuditEvent(
            action: $isAccountMember ? 'account.impersonation_ended' : 'platform.impersonation_ended',
            actorType: $marker->actorType,
            actorId: $marker->operator,
            organizationId: $marker->organizationId,
            targetType: 'user',
            targetId: $marker->subject,
            context: ['duration_seconds' => max(0, now()->getTimestamp() - $marker->startedAt)],
            ip: $request->ip(),
        ));

        // End the subject session: revoke the framework row where we can (best
        // effort — it may live in another plane), then drop the cookie keys. Once
        // the keys are gone and the id is rotated below, the subject session is
        // unresumable regardless of the row's state.
        $sessionId = $request->session()->get(PlatformAuth::SESSION_KEY);
        if (is_string($sessionId)) {
            $this->sessions->revoke($sessionId);
        }

        // The whole subject side of the browser session, not just the derived keys: the
        // impersonated subject was ADDED to the held-account set by establish(), and an
        // entry left there is an account the browser can switch back into — and, in the
        // multi-account branch of logout(), one it activates by itself.
        $this->forgetSubjectSession($request);

        // Restore ONLY the acting principal captured (and validated) at start, and
        // re-pin the selection it was working under — the environment an admin session is
        // anchored to, the plane an operator had the console aimed at.
        if ($isAccountMember) {
            // The acting person is resolved FRESH from the member id captured at start,
            // never carried in the marker. A member removed (or unlinked) while the
            // impersonation was running restores nothing: they come back to the sign-in
            // door, not to a live control-plane session.
            $subjectId = $this->members->find($marker->operator)?->subject_id;

            // The anchor goes back only if the identity did. An anchor without a session
            // authenticates nobody, but leaving one behind for whoever holds the browser
            // next is exactly the sort of half-restored state this method exists to avoid.
            if ($this->resumePrincipal($request, $marker, $subjectId) && $marker->environmentKey !== null) {
                $request->session()->put(EnvironmentAdminAuth::ENV_KEY, $marker->environmentKey);
            }
        } else {
            $this->restoreOperator($request, $marker);
        }

        $request->session()->forget(self::SESSION_KEY);

        // Rotate the id: the operator returns on a fresh session, not the one the
        // subject was just holding.
        $request->session()->regenerate();
    }

    /**
     * Put the acting principal's OWN browser identity aside, returning what to resume on
     * exit.
     *
     * The subject session, and EVERYTHING of it: the active key, the pinned org, the
     * held-account SET and the active pointer. The set is the one an "operator key" model
     * never had. {@see PlatformAuth} keeps several signed-in accounts and can switch
     * between them without re-authenticating, so an operator left in it is an operator the
     * impersonated session can become again. The Livewire call guard refuses the switch
     * action today; a deny-list that has to stay complete is not what this property should
     * rest on.
     *
     * It used to have to put aside a second store as well — the account-member session,
     * which is where staff actually sign in and which {@see ConsoleScope} also resolved
     * operator authority from. Leaving that one behind LOOKED safe, because the resolver
     * preferred the subject session and during an impersonation that is the victim's. It
     * is one store now, so there is no second place authority can be left sitting.
     *
     * The framework session ROW is deliberately not revoked — it is suspended, and
     * resumed as itself on exit, so the operator comes back with the authentication facts
     * (amr, auth_time, device history) they actually signed in with rather than a fresh
     * session minted on their behalf by the act of leaving an impersonation.
     *
     * Any OTHER account the browser held is dropped and not resumed. One acting principal
     * went in, one comes back.
     */
    private function suspendPrincipal(Request $request): SuspendedIdentity
    {
        $sessionId = $request->session()->get(PlatformAuth::SESSION_KEY);

        $this->forgetSubjectSession($request);
        $request->session()->forget([
            AccountAuth::PENDING_KEY,
            // The environment ANCHOR, on both doors. The account-member branch captures
            // it first so exit can put it back; the operator branch simply drops it,
            // because an impersonated session must not inherit a binding either — the
            // identity behind it is gone, and a selection left over from someone who is
            // not here is not a selection.
            EnvironmentAdminAuth::ENV_KEY,
        ]);
        $this->target->release();

        return new SuspendedIdentity(
            subjectSessionId: is_string($sessionId) && $sessionId !== '' ? $sessionId : null,
        );
    }

    /**
     * Resume the operator: their own subject session, and the plane they had the
     * console aimed at.
     *
     * Nothing in the marker is taken as an authorization. The operator id is resolved
     * FRESH to the subject it points at, and the suspended session is accepted only if
     * that row is still live AND belongs to that subject — so the id is a lookup hint
     * and cannot name someone else's session. An operator deleted, unlinked, or whose
     * session expired or was revoked during the window restores nothing and returns to
     * the sign-in door.
     *
     * Note what is NOT re-checked here: whether the operator is still ACTIVE. Suspension
     * takes effect through {@see ConsoleScope::operator()}, which asks on every request
     * — so a suspended operator gets their ordinary subject session back and finds the
     * platform pages gone, which is exactly what a suspension in a session they already
     * hold has to look like.
     */
    private function restoreOperator(Request $request, ImpersonationMarker $marker): void
    {
        $subjectId = $this->operators->find($marker->operator)?->subject_id;

        if (! is_string($subjectId) || $subjectId === '') {
            return;
        }

        if (! $this->resumePrincipal($request, $marker, $subjectId)) {
            return;
        }

        if ($marker->environmentKey !== null) {
            $this->target->pointAt($marker->environmentKey);
        }
    }

    /**
     * Put the suspended session back — and only if it still belongs to the acting
     * principal, freshly resolved from the id captured at start.
     *
     * That re-binding is the whole safety argument. The marker names a session id; it is
     * not trusted to be the principal's, so a marker that somehow named someone else's
     * session restores nothing at all rather than handing the browser an identity it never
     * had.
     *
     * Returns whether the identity came back — false means the acting person returns to
     * the sign-in door, which is what a revoked session, a deleted operator or a member
     * unlinked during the window has to look like.
     */
    private function resumePrincipal(Request $request, ImpersonationMarker $marker, ?string $subjectId): bool
    {
        $sessionId = $marker->suspended->subjectSessionId;

        if ($subjectId === null || $sessionId === null) {
            return false;
        }

        // Read in the PLATFORM ROOT's scope: `auth_sessions` is environment-owned, the
        // control plane's people are subjects of the root, and the ambient environment
        // right now is the TENANT's (we are still inside the impersonated request).
        // Unscoped it would find nothing and silently drop the operator at the door.
        $session = $this->platformRoot->run(
            fn (): ?Session => $this->sessions->active($sessionId),
        );

        if ($session === null || $session->user_id !== $subjectId) {
            return false;
        }

        $this->platformAuth->adopt($request, $session);

        return true;
    }

    /**
     * Drop every trace of a subject sign-in from the browser session — including the
     * multi-account set, which the derived keys alone do not cover.
     */
    private function forgetSubjectSession(Request $request): void
    {
        $request->session()->forget([
            PlatformAuth::SESSION_KEY,
            PlatformAuth::ORG_KEY,
            PlatformAuth::ACCOUNTS_KEY,
            PlatformAuth::ACTIVE_KEY,
        ]);
    }

    /**
     * The validated marker, or null when not impersonating.
     */
    public function active(): ?ImpersonationMarker
    {
        $data = session()->get(self::SESSION_KEY);

        if (! is_array($data)) {
            return null;
        }

        $operator = $data['operator'] ?? null;
        $subject = $data['subject'] ?? null;
        $org = $data['org'] ?? null;
        $startedAt = $data['started_at'] ?? null;

        if (! is_string($operator) || $operator === ''
            || ! is_string($subject) || $subject === ''
            || ! is_string($org) || $org === ''
            || ! is_int($startedAt)) {
            return null;
        }

        $env = $data['env'] ?? null;
        $reason = $data['reason'] ?? null;
        // Absent on a marker written before the acting principal became a subject — an
        // impersonation in flight across the deploy. Empty restores nothing, which sends
        // the operator back to the sign-in door rather than to a guessed session.
        $suspended = SuspendedIdentity::fromSession($data['suspended'] ?? null);
        // Back-compat: a marker written before actor_type existed is an operator one.
        $actorType = ($data['actor_type'] ?? null) === ActorType::AccountMember->value
            ? ActorType::AccountMember
            : ActorType::Operator;

        return new ImpersonationMarker(
            actorType: $actorType,
            operator: $operator,
            subject: $subject,
            organizationId: $org,
            environmentKey: is_string($env) && $env !== '' ? $env : null,
            reason: is_string($reason) && $reason !== '' ? $reason : null,
            startedAt: $startedAt,
            suspended: $suspended,
        );
    }

    public function isImpersonating(): bool
    {
        return $this->active() !== null;
    }

    /**
     * Seconds until the time-box forces an exit. Zero once expired (or not active).
     */
    public function expiresInSeconds(): int
    {
        $marker = $this->active();

        if ($marker === null) {
            return 0;
        }

        return max(0, self::MAX_MINUTES * 60 - (now()->getTimestamp() - $marker->startedAt));
    }
}
