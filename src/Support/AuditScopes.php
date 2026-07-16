<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Support;

use Cbox\Id\Kernel\Audit\Models\AuditEntry;

/**
 * Enumerates the audit chains present in the trail: each distinct organization plus
 * the system trail (`null`). `null` selects the system scope inside the audit
 * reader/log, so the list is directly usable as `?string $organizationId` arguments.
 */
final class AuditScopes
{
    /**
     * @return list<string|null>
     */
    public static function all(): array
    {
        $ids = AuditEntry::query()
            ->distinct()
            ->orderByRaw('organization_id is null')
            ->orderBy('organization_id')
            ->pluck('organization_id');

        $scopes = [];

        foreach ($ids as $id) {
            $scopes[] = is_string($id) ? $id : null;
        }

        return $scopes;
    }
}
