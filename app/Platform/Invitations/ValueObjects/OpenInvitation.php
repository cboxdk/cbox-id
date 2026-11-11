<?php

declare(strict_types=1);

namespace App\Platform\Invitations\ValueObjects;

use Cbox\Id\Organization\Enums\MembershipRole;

/** A live invitation as the person it was sent to sees it: which organization, from whom, as what. */
final readonly class OpenInvitation
{
    public function __construct(
        public string $id,
        public string $organizationName,
        public MembershipRole $role,
        public ?string $inviterName = null,
    ) {}
}
