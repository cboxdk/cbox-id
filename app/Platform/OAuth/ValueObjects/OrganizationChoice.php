<?php

declare(strict_types=1);

namespace App\Platform\OAuth\ValueObjects;

use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * One organization a person may bind an authorization to: live, in this environment, and
 * held through an ACTIVE membership.
 *
 * The role is the tier the token will carry as `org_role`, shown on the picker so the
 * person can tell their own team from one they were merely added to.
 */
final readonly class OrganizationChoice
{
    public function __construct(
        public string $id,
        public string $name,
        public MembershipRole $role,
    ) {}
}
