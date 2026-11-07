<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\GrantRefused;
use Cbox\Id\AccessControl\Exceptions\RoleNotTenantAssignable;

/**
 * The one place a role is granted to a person from the console.
 *
 * Console prechecks provide the tenant-plane refusal and descriptive conflict response.
 * The framework repeats the policy and segregation-of-duties checks inside the locked
 * grant transaction, so a concurrent change cannot bypass them after these prechecks.
 * Both tenant and environment administration use this service; tenant paths must use
 * grantAsTenant so staff-only roles remain unavailable to organization administrators.
 */
final readonly class GrantAccessRole
{
    public function __construct(
        private Roles $roles,
        private SodGuard $sod,
    ) {}

    /**
     * Grant a role from the ENVIRONMENT plane, or return the refusal explaining why
     * segregation of duties forbids it. Null means the grant was made.
     *
     * Any role this organization may hold, staff-only ones included — an environment
     * administrator giving the vendor's support lead "Support" inside one customer is this
     * call. Every tenant-facing path uses {@see grantAsTenant()}.
     */
    public function grant(
        string $organizationId,
        string $userId,
        string $roleId,
        GrantSource $source = GrantSource::Manual,
    ): ?SodRefusal {
        $refusal = $this->sod->refuse($organizationId, $userId, $roleId);

        if ($refusal !== null) {
            return $refusal;
        }

        $this->roles->assign($organizationId, $userId, $roleId, $source);

        return null;
    }

    /**
     * Grant a role from the ORGANIZATION plane — a tenant administrator's People page, an
     * invitation being accepted — through the framework's
     * {@see Roles::assignAsTenant()}, which refuses a staff-only role before anything is
     * written. The console's own pickers already leave those out
     * ({@see OrgAccessRoles::tenantAssignable()}); this is the guard that holds when a
     * posted id did not come from a picker.
     *
     * @throws RoleNotTenantAssignable
     * @throws GrantRefused
     */
    public function grantAsTenant(
        string $organizationId,
        string $userId,
        string $roleId,
        GrantSource $source = GrantSource::Manual,
    ): ?SodRefusal {
        // Asked before segregation of duties, so a staff role is refused as what it is
        // rather than reported as a conflict with something the person holds.
        $this->roles->assertTenantAssignable($organizationId, $roleId);

        $refusal = $this->sod->refuse($organizationId, $userId, $roleId);

        if ($refusal !== null) {
            return $refusal;
        }

        $this->roles->assignAsTenant($organizationId, $userId, $roleId, $source);

        return null;
    }

    /**
     * Revoking never conflicts — a conflict is a pair being HELD, so removing half of
     * one can only improve the position. Here so both directions read from one service
     * and a caller does not have to reach past it for the ungated half.
     */
    public function revoke(string $organizationId, string $userId, string $roleId): void
    {
        $this->roles->unassign($organizationId, $userId, $roleId);
    }
}
