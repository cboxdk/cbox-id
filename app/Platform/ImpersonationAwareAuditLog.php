<?php

declare(strict_types=1);

namespace App\Platform;

use App\Providers\PlatformServiceProvider;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Models\AuditCheckpoint;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Audit\ValueObjects\ChainVerification;

/**
 * Dual-attribution audit for support impersonation (a privileged-access action).
 *
 * A container decorator over the framework's {@see AuditLog} — bound by extending
 * the existing binding in {@see PlatformServiceProvider}, so ALL
 * audit (framework-emitted included) flows through it. While an impersonation
 * marker is active it stamps the acting operator onto every recorded event's
 * context WITHOUT touching the event's real actor. The trail therefore reconstructs
 * "operator X, acting as user Y, did Z": the normal `actorId` stays the impersonated
 * subject, and `context.impersonated_by` names the operator behind the session.
 *
 * Read-only impersonation ({@see ImpersonationCallGuard}) means there are few, if
 * any, in-window mutation events to attribute — but this makes the guarantee
 * total: anything that does get recorded during the window carries both identities,
 * with no per-call-site wiring to forget.
 *
 * {@see Impersonation} is resolved LAZILY (never constructor-injected): Impersonation
 * itself depends on AuditLog, so injecting it into this decorator — which the
 * container builds while resolving AuditLog — would be a resolution cycle. Reading
 * it at record() time sidesteps that entirely.
 */
final class ImpersonationAwareAuditLog implements AuditLog
{
    private ?Impersonation $impersonation = null;

    public function __construct(private readonly AuditLog $inner) {}

    public function record(AuditEvent $event): AuditEntry
    {
        return $this->inner->record($this->stamp($event));
    }

    public function verifyChain(?string $organizationId = null, int $fromSequence = 1, ?int $toSequence = null): ChainVerification
    {
        return $this->inner->verifyChain($organizationId, $fromSequence, $toSequence);
    }

    public function checkpoint(?string $organizationId = null): AuditCheckpoint
    {
        return $this->inner->checkpoint($organizationId);
    }

    /**
     * Return the event unchanged when not impersonating; otherwise a copy with the
     * acting operator merged into its context. AuditEvent is readonly, so this
     * rebuilds it field-for-field.
     */
    private function stamp(AuditEvent $event): AuditEvent
    {
        $marker = $this->impersonation()->active();

        if ($marker === null) {
            return $event;
        }

        return new AuditEvent(
            action: $event->action,
            actorType: $event->actorType,
            actorId: $event->actorId,
            organizationId: $event->organizationId,
            targetType: $event->targetType,
            targetId: $event->targetId,
            context: array_merge($event->context, [
                'impersonation' => true,
                'impersonated_by' => $marker['operator'],
            ]),
            ip: $event->ip,
        );
    }

    private function impersonation(): Impersonation
    {
        return $this->impersonation ??= app(Impersonation::class);
    }
}
