<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;

/**
 * The one place a role is granted to a person from the console.
 *
 * Segregation of duties is a PRE-GRANT gate the framework publishes and the host has to
 * call — `wouldViolate()` is the whole API, and the framework's docs list "the host must
 * call it" under honest limits. The console called it on two of six grant paths: the
 * Members page and invitation acceptance. The four that skipped it were all on the
 * environment-admin plane, where the most privileged administrators work, so an env
 * admin could create exactly the toxic combination the Governance screen then reports.
 *
 * Routing every grant through this service is what makes the gate hold: a new grant
 * surface added later either uses this and is covered, or does not compile against the
 * pattern its neighbours follow.
 */
final readonly class GrantAccessRole
{
    public function __construct(
        private Roles $roles,
        private SodGuard $sod,
    ) {}

    /**
     * Grant a role, or return the refusal explaining why segregation of duties forbids
     * it. Null means the grant was made.
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
     * Revoking never conflicts — a conflict is a pair being HELD, so removing half of
     * one can only improve the position. Here so both directions read from one service
     * and a caller does not have to reach past it for the ungated half.
     */
    public function revoke(string $organizationId, string $userId, string $roleId): void
    {
        $this->roles->unassign($organizationId, $userId, $roleId);
    }
}
