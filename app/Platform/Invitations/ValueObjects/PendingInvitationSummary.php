<?php

declare(strict_types=1);

namespace App\Platform\Invitations\ValueObjects;

use Carbon\CarbonInterface;
use Cbox\Id\Organization\Enums\MembershipRole;

/** One invitation nobody has accepted yet, as every invite surface lists it. */
final readonly class PendingInvitationSummary
{
    public function __construct(
        public string $id,
        public string $email,
        public MembershipRole $role,
        public CarbonInterface $expiresAt,
        public ?CarbonInterface $invitedAt = null,
        public ?string $inviterName = null,
        public ?string $appName = null,
    ) {}
}
