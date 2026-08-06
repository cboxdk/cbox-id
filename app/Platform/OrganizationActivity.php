<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The management-plane activity log.
 *
 * Organization administration — creating environments, inviting members, minting keys —
 * happens ABOVE any one environment, so it has no tenant chain of its own. These events go
 * into the framework's tamper-evident audit log under the ORGANIZATION id as the chain
 * scope, so each customer gets a hash-linked, gap-detectable trail of its own, isolated
 * from every other customer and from the tenant chains, with no new schema.
 *
 * THE SCOPE DID NOT MOVE when the account plane went, and that is worth stating: it was
 * always `accounts.id`, and an account's id became an organization's id rather than being
 * translated into one. Existing entries would still resolve — not that any survive a
 * `migrate:fresh`, but the property is what makes the rename a rename.
 *
 * A single funnel keeps the action sites thin: a controller or Livewire action calls
 * {@see record()} with what changed; the activity page reads {@see recent()}.
 */
final class OrganizationActivity
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * Record a management-plane event, attributed to the acting member.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $organizationId,
        string $action,
        ?string $actorId,
        ?string $targetType = null,
        ?string $targetId = null,
        array $context = [],
        ?Request $request = null,
    ): void {
        $this->audit->record(new AuditEvent(
            action: $action,
            actorType: ActorType::OrganizationMember,
            actorId: $actorId,
            // The organization id IS the audit chain scope (see class docblock).
            organizationId: $organizationId,
            targetType: $targetType,
            targetId: $targetId,
            context: $context,
            ip: $request?->ip(),
        ));
    }

    /**
     * The organization's most recent activity, newest first.
     *
     * @return Collection<int, AuditEntry>
     */
    public function recent(string $organizationId, int $limit = 100): Collection
    {
        return AuditEntry::query()
            ->where('scope', $organizationId)
            ->orderByDesc('sequence')
            ->limit(max(1, min(500, $limit)))
            ->get();
    }
}
