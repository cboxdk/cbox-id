<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;

/**
 * What changes ABOUT Radar leaves on the platform's own audit trail: the mode switched, a
 * built-in rule retuned, a rule or a list entry added, changed, reordered or removed — who
 * did it, and the before and after.
 *
 * Not the decisions. Those are pre-authentication telemetry from unauthenticated traffic and
 * live in `risk_decisions` with their own retention; docs/security/adaptive-risk.md measured
 * why they cannot go on the hash chain. A list entry's value is NOT copied into the trail
 * either — it may be an address — only its kind and list; the entry itself is the record.
 */
final readonly class RadarTrail
{
    public const string MODE_CHANGED = 'radar.mode_changed';

    public const string BUILTINS_UPDATED = 'radar.builtin_rules_updated';

    public const string RULE_CREATED = 'radar_rule.created';

    public const string RULE_UPDATED = 'radar_rule.updated';

    public const string RULE_DELETED = 'radar_rule.deleted';

    public const string RULES_REORDERED = 'radar_rule.reordered';

    public const string LIST_ENTRY_ADDED = 'radar_list_entry.added';

    public const string LIST_ENTRY_REMOVED = 'radar_list_entry.removed';

    public function __construct(private AuditLog $log) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, string $targetType, ?string $targetId, AuditActor $actor, array $context = []): void
    {
        $this->log->record(new AuditEvent(
            action: $action,
            actorType: $actor->type,
            actorId: $actor->id,
            organizationId: null,
            targetType: $targetType,
            targetId: $targetId,
            context: $context,
        ));
    }
}
