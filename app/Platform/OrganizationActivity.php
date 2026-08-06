<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Platform\PlatformRoot;
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
    public function __construct(
        private readonly AuditLog $audit,
        private readonly PlatformRoot $platformRoot,
    ) {}

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
        // IN THE PLATFORM ROOT, here rather than at each call site. An audit entry is
        // environment-owned and this chain lives in exactly one environment; written under
        // whatever scope the caller happened to be standing in, the same organization's
        // entries land in different environments depending on which page wrote them — and
        // the activity page reads under one, so it would show some and silently omit the
        // rest. Two callers already pinned it by hand for exactly this reason; a third that
        // forgets is the bug, so the funnel does it once.
        $this->platformRoot->run(fn () => $this->audit->record(new AuditEvent(
            action: $action,
            actorType: ActorType::OrganizationMember,
            actorId: $actorId,
            // The organization id IS the audit chain scope (see class docblock).
            organizationId: $organizationId,
            targetType: $targetType,
            targetId: $targetId,
            context: $context,
            ip: $request?->ip(),
        )));
    }

    /**
     * The organization's most recent activity, newest first.
     *
     * @return Collection<int, AuditEntry>
     */
    public function recent(string $organizationId, int $limit = 100): Collection
    {
        return $this->platformRoot->run(fn (): Collection => AuditEntry::query()
            ->where('scope', $organizationId)
            ->orderByDesc('sequence')
            ->limit(max(1, min(500, $limit)))
            ->get()) ?? new Collection;
    }
}
