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
 *
 * `$roles` are the app and custom roles the invitation will grant beside the built-in
 * `$role` — only the ones accepting would still grant, so the page never promises a role
 * that was made staff-only or retired while the invitation waited.
 */
final readonly class InvitationPreview
{
    public function __construct(
        public string $email,
        public string $organizationName,
        public MembershipRole $role,
        public ?string $inviterName = null,
        public ?string $appName = null,
        /** @var list<InvitedRole> */
        public array $roles = [],
    ) {}
}
