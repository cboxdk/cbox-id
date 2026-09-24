<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\AuthenticateEnvironmentApi;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditCheckpoint;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Audit\ValueObjects\ChainVerification;

/**
 * WHO DID IT, WHEN THE ENVIRONMENT MANAGEMENT API DID IT: the key.
 *
 * A container decorator over {@see AuditLog}, like {@see ImpersonationAwareAuditLog}, so it
 * sees every entry — the framework's included. That matters here more than anywhere: the
 * management API is a thin layer over framework services (organizations, memberships,
 * invitations, roles, apps), and those record their own entries with the actor they know,
 * which for a machine caller is usually nobody. `organization.created` is written as the
 * system; `organization.member_added` too. Read back, an organization provisioned by a
 * vendor's backend looked like it had provisioned itself.
 *
 * While {@see AuthenticateEnvironmentApi} has a key on the request:
 *
 * - an entry with NO actor, or whose actor id IS the key's id (a service that takes an
 *   actor id as a plain string, like `Organizations::archive()`, records it under its own
 *   idea of the actor type), becomes `ActorType::Service` with the key's id;
 * - an entry that names a PERSON keeps them — the framework's ownership transfer, for one,
 *   is recorded as the outgoing owner's act — and gains the key in its context, so the
 *   trail still says which credential asked for it.
 *
 * Either way `context.environment_api_key` names the key, so an auditor can list
 * everything one credential did without knowing which actions it can reach.
 *
 * The context is read LAZILY, per entry, for the same reason Impersonation is: it is a
 * `scoped` binding, and this decorator is built once with the audit log.
 */
final class EnvironmentKeyAuditLog implements AuditLog
{
    /** Where the key's id is recorded on every entry it causes. */
    public const string CONTEXT_KEY = 'environment_api_key';

    public function __construct(private readonly AuditLog $inner) {}

    public function record(AuditEvent $event): AuditEntry
    {
        return $this->inner->record($this->attribute($event));
    }

    public function verifyChain(?string $organizationId = null, int $fromSequence = 1, ?int $toSequence = null): ChainVerification
    {
        return $this->inner->verifyChain($organizationId, $fromSequence, $toSequence);
    }

    public function headSequence(?string $organizationId = null): int
    {
        return $this->inner->headSequence($organizationId);
    }

    public function checkpoint(?string $organizationId = null): AuditCheckpoint
    {
        return $this->inner->checkpoint($organizationId);
    }

    private function attribute(AuditEvent $event): AuditEvent
    {
        $key = app(EnvironmentApiContext::class)->key();

        if ($key === null) {
            return $event;
        }

        $theKey = $event->actorId === null || $event->actorId === $key->id;

        return new AuditEvent(
            action: $event->action,
            actorType: $theKey ? ActorType::Service : $event->actorType,
            actorId: $theKey ? $key->id : $event->actorId,
            organizationId: $event->organizationId,
            targetType: $event->targetType,
            targetId: $event->targetId,
            context: array_merge($event->context, [self::CONTEXT_KEY => $key->id]),
            ip: $event->ip,
        );
    }
}
