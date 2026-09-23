<?php

declare(strict_types=1);

namespace App\Platform\Invitations\ValueObjects;

use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * What an invitation says, read WITHOUT spending it — for the page an emailed link opens.
 *
 * `$inviterName` and `$appName` are null when they are not known (an invitation sent before
 * the app recorded them, or one sent by a machine credential): the page leaves the line out
 * rather than inventing one.
 */
final readonly class InvitationPreview
{
    public function __construct(
        public string $email,
        public string $organizationName,
        public MembershipRole $role,
        public ?string $inviterName = null,
        public ?string $appName = null,
    ) {}
}
