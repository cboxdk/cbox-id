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
 * The account-plane activity log. Account administration (creating environments,
 * inviting members, minting environment keys) happens ABOVE any organization, so it
 * has no tenant chain of its own — this records those events into the framework's
 * tamper-evident audit log under the ACCOUNT id as the chain scope. Each account
 * therefore gets its own hash-linked, gap-detectable activity trail, isolated from
 * every other account and from the tenant chains, with zero new schema.
 *
 * A single funnel keeps the action sites thin: a controller or Livewire action calls
 * {@see record()} with what changed; the activity page reads {@see recent()}.
 */
final class AccountActivity
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * Record an account-plane event, attributed to the acting account member.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $accountId,
        string $action,
        ?string $actorId,
        ?string $targetType = null,
        ?string $targetId = null,
        array $context = [],
        ?Request $request = null,
    ): void {
        $this->audit->record(new AuditEvent(
            action: $action,
            actorType: ActorType::AccountMember,
            actorId: $actorId,
            // The account id IS the audit chain scope (see class docblock).
            organizationId: $accountId,
            targetType: $targetType,
            targetId: $targetId,
            context: $context,
            ip: $request?->ip(),
        ));
    }

    /**
     * The account's most recent activity, newest first.
     *
     * @return Collection<int, AuditEntry>
     */
    public function recent(string $accountId, int $limit = 100): Collection
    {
        return AuditEntry::query()
            ->where('scope', $accountId)
            ->orderByDesc('sequence')
            ->limit(max(1, min(500, $limit)))
            ->get();
    }
}
