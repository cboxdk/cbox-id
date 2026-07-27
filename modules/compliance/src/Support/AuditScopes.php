<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Support;

use Cbox\Id\Kernel\Audit\Models\AuditEntry;

/**
 * Enumerates the audit chains present in the trail: each distinct organization plus
 * the system trail (`null`). `null` selects the system scope inside the audit
 * reader/log, so the list is directly usable as `?string $organizationId` arguments.
 */
class AuditScopes
{
    /**
     * @return list<string|null>
     */
    public static function all(): array
    {
        // Ordered in PHP, not SQL, on purpose. The system trail must sort last, and
        // the natural spelling — `ORDER BY organization_id IS NULL, organization_id`
        // — is rejected by PostgreSQL alongside SELECT DISTINCT: every ORDER BY
        // expression has to appear in the select list, and `organization_id IS NULL`
        // does not. MySQL and SQLite accept it, which is why this shipped. There is
        // no portable SQL spelling that keeps both the DISTINCT and the expression,
        // and the result set is bounded by the number of organizations that have
        // ever been audited, so sorting it here costs nothing.
        $ids = AuditEntry::query()
            ->distinct()
            ->pluck('organization_id');

        $scopes = [];

        foreach ($ids as $id) {
            $scopes[] = is_string($id) ? $id : null;
        }

        usort($scopes, static function (?string $a, ?string $b): int {
            // System trail (null) last; organizations alphabetically before it.
            return match (true) {
                $a === null && $b === null => 0,
                $a === null => 1,
                $b === null => -1,
                default => strcmp($a, $b),
            };
        });

        return $scopes;
    }
}
