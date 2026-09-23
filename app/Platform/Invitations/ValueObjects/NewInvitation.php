<?php

declare(strict_types=1);

namespace App\Platform\Invitations\ValueObjects;

use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * Everything one invitation into an organization carries, from whichever surface sends it.
 *
 * `$accessRoleIds`, `$clientId` and `$returnTo` are CLAIMS until the service has checked
 * them against the organization: an access role must be assignable there, and a return
 * address must belong to an app the organization can use. They arrive raw so every
 * surface is checked by the same code rather than by its own copy of it.
 */
final readonly class NewInvitation
{
    /**
     * @param  list<string>  $accessRoleIds  roles to grant the moment the invitee accepts
     */
    public function __construct(
        public string $organizationId,
        public string $email,
        public MembershipRole $role,
        public Inviter $inviter,
        public array $accessRoleIds = [],
        public ?string $clientId = null,
        public ?string $returnTo = null,
    ) {}
}
