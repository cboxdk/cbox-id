<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\Kernel\Audit\Models\AuditEntry;

/**
 * One audit trail entry — `AuditEntry` in the spec. The chain's hashes stay inside: they
 * prove the trail to whoever verifies it, and a client paging through it has no use for
 * them. `id` is the cursor.
 */
final class AuditEntryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(AuditEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'action' => $entry->action,
            'actor_type' => $entry->actor_type->value,
            'actor_id' => $entry->actor_id,
            'organization_id' => $entry->organization_id,
            'target_type' => $entry->target_type,
            'target_id' => $entry->target_id,
            // An object even when empty: `[]` would encode as a JSON array.
            'context' => $entry->context === [] ? (object) [] : $entry->context,
            'ip' => $entry->ip,
            'recorded_at' => Timestamp::of($entry->recorded_at),
        ];
    }
}
