<?php

declare(strict_types=1);

namespace App\Http\Resources\OrgPlane;

use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\NativeProduct\NativeProductMembers;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Membership;

/**
 * A member of the workspace's team — `Member` in the workspace spec — and a pending
 * invitation onto it (`TeamInvitation`).
 *
 * A MEMBER IS TWO ROWS: the membership carries the authority (role, environment grants)
 * and the subject carries the person (name, address). Presented as one object because that
 * is what an API consumer means by "member"; the caller hydrates both, and a roster does it
 * in one pass rather than per row.
 */
final class MemberResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Membership $member, ?Subject $person): array
    {
        return [
            ...app(NativeProductMembers::class)->access($member),
            'id' => $member->id,
            // FROM THE SUBJECT, not the membership. A membership carries authority, not
            // identity; reading a name or an address off it is what the two-row split makes
            // impossible, which is the point of the split.
            'email' => $person?->email,
            'name' => $person?->name,
            'role' => $member->role->value,
            'status' => $member->status->value,
            'environment_ids' => app(NativeEnvironmentAccess::class)->ids($member->organization_id, $member->user_id),
            'environment_access_version' => app(NativeEnvironmentAccess::class)->version($member),
            'all_environments' => $member->role === MembershipRole::Owner || $member->all_environments === true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function invitation(PendingInvitationSummary $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'app_role_label' => $invitation->appRoleLabel,
            'roles' => $invitation->appRoleKeys,
            'invited_by' => $invitation->inviterName,
            'invited_at' => $invitation->invitedAt?->toIso8601String(),
            'expires_at' => $invitation->expiresAt->toIso8601String(),
        ];
    }
}
