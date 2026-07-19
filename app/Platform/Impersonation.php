<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Illuminate\Http\Request;

/**
 * Support impersonation: a platform operator "steps into" a specific org member's
 * session for support, then exits back to the operator console.
 *
 * All state lives in ONE session marker ({@see SESSION_KEY}) so controllers,
 * middleware and views stay thin. The security model is deliberately strict:
 *
 *  - While impersonating the browser is PURELY the subject — the operator key is
 *    forgotten on start, so operator routes are unreachable without exiting first.
 *  - The subject session carries an `impersonation` amr, so it is never mistaken
 *    for a real login by anything that inspects how the session authenticated.
 *  - Both the start and the end are recorded on the tenant's audit trail, attributed
 *    to the acting operator ({@see ActorType::Operator}).
 *  - A forgotten impersonation self-terminates after {@see MAX_MINUTES} minutes
 *    (enforced per request by EnforceImpersonationWindow).
 *
 * The operator id in the marker is the one captured (and authorized) at start; it
 * is the ONLY thing restored to the operator session on exit — nothing the subject
 * did while impersonated can influence which operator comes back.
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
    ) {}

    /**
     * Step into the subject's session. The caller has ALREADY authorized this
     * (the operator is real, and $subjectId is a real member of $orgId in the
     * operator's currently-pinned plane) and captured a justification $reason —
     * impersonation is privileged access, so why it happened is recorded up front.
     */
    public function start(Request $request, string $operatorId, string $subjectId, string $orgId, string $reason): void
    {
        // Capture the operator's currently-targeted plane so exit can re-pin it —
        // it is the plane the org lives in (the operator reached the member in-plane).
        $env = $request->session()->get(OperatorAuth::ENV_KEY);

        // Become PURELY the subject: drop the operator key so operator routes are
        // unreachable while impersonating. The only operator identity that survives
        // is the id recorded in the marker below — restored, and only that, on exit.
        $request->session()->forget(OperatorAuth::SESSION_KEY);

        $request->session()->put(self::SESSION_KEY, [
            'actor_type' => ActorType::Operator->value,
            'operator' => $operatorId,
            'subject' => $subjectId,
            'org' => $orgId,
            'env' => is_string($env) && $env !== '' ? $env : null,
            'reason' => $reason,
            'started_at' => now()->getTimestamp(),
        ]);

        // Start the subject session with an `impersonation` amr so it is never
        // mistaken for a real login. establish() regenerates the session id; the
        // marker (session data) survives the regeneration.
        $this->platformAuth->establish($request, $subjectId, ['impersonation'], $orgId);

        $this->audit->record(new AuditEvent(
            action: 'operator.impersonation_started',
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
     * and captured a justification. Here the env-admin session keys are the ones
     * forgotten — so the /admin control plane is unreachable while impersonating —
     * and the acting principal is recorded as an {@see ActorType::AccountMember}.
     * The env to re-pin on exit is the env-admin's bound environment.
     */
    public function startAsAccountMember(Request $request, string $memberId, string $subjectId, string $orgId, string $reason): void
    {
        // The env-admin session is bound to exactly one environment; re-pin it on exit.
        $env = $request->session()->get(EnvironmentAdminAuth::ENV_KEY);

        // Become PURELY the subject: drop the env-admin keys so /admin is unreachable
        // until exit. Only the member id in the marker survives, restored on exit.
        $request->session()->forget([EnvironmentAdminAuth::SESSION_KEY, EnvironmentAdminAuth::ENV_KEY]);

        $request->session()->put(self::SESSION_KEY, [
            'actor_type' => ActorType::AccountMember->value,
            'operator' => $memberId,
            'subject' => $subjectId,
            'org' => $orgId,
            'env' => is_string($env) && $env !== '' ? $env : null,
            'reason' => $reason,
            'started_at' => now()->getTimestamp(),
        ]);

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
        $marker = $this->active();

        if ($marker === null) {
            return;
        }

        $isAccountMember = $marker->isAccountMember();

        $this->audit->record(new AuditEvent(
            action: $isAccountMember ? 'account.impersonation_ended' : 'operator.impersonation_ended',
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
        $request->session()->forget([PlatformAuth::SESSION_KEY, PlatformAuth::ORG_KEY]);

        // Restore ONLY the acting principal captured (and validated) at start, and
        // re-pin the plane it was working in — the env-admin session for an account
        // member, the operator session otherwise.
        if ($isAccountMember) {
            $request->session()->put(EnvironmentAdminAuth::SESSION_KEY, $marker->operator);
            if ($marker->environmentKey !== null) {
                $request->session()->put(EnvironmentAdminAuth::ENV_KEY, $marker->environmentKey);
            }
        } else {
            $request->session()->put(OperatorAuth::SESSION_KEY, $marker->operator);
            if ($marker->environmentKey !== null) {
                $request->session()->put(OperatorAuth::ENV_KEY, $marker->environmentKey);
            }
        }

        $request->session()->forget(self::SESSION_KEY);

        // Rotate the id: the operator returns on a fresh session, not the one the
        // subject was just holding.
        $request->session()->regenerate();
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
