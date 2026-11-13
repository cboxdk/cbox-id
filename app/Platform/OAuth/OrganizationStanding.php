<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Platform\OrganizationAccess;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;

/**
 * Whether an organization a credential speaks for has been closed to it: suspended or
 * archived ({@see OrganizationAccess::isRevoked()}).
 *
 * Read off the row itself, above every scope. A credential names its organization by id,
 * and the question is that organization's status, wherever the request happens to stand:
 * a tenant-scoped query from another organization's context would find no row and answer
 * "not closed", which is the failure this exists to prevent. Ids are ULIDs, so the
 * unscoped read cannot answer for another environment's organization.
 *
 * An organization with no row is NOT closed here. The callers ask about ids a signed
 * token or a stored grant carries; an erased organization takes its memberships and
 * grants with it, and failing every token whose `org` cannot be read would turn a
 * missing row into a platform-wide outage.
 */
final class OrganizationStanding
{
    public static function isClosed(?string $organizationId): bool
    {
        if ($organizationId === null || $organizationId === '') {
            return false;
        }

        $status = Organization::query()->withoutGlobalScopes()->whereKey($organizationId)->value('status');

        if (is_string($status)) {
            $status = OrganizationStatus::tryFrom($status);
        }

        return $status instanceof OrganizationStatus && OrganizationAccess::isRevoked($status);
    }
}
