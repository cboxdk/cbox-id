<?php

declare(strict_types=1);

namespace App\Http\Resources\Workspace;

use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use Cbox\Id\Identity\ValueObjects\Subject;
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
            'id' => $member->id,
            // FROM THE SUBJECT, not the membership. A membership carries authority, not
            // identity; reading a name or an address off it is what the two-row split makes
            // impossible, which is the point of the split.
            'email' => $person?->email,
            'name' => $person?->name,
            'role' => $member->role->value,
            'status' => $member->status->value,
            'all_environments' => $member->all_environments === true,
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
            'invited_by' => $invitation->inviterName,
            'invited_at' => $invitation->invitedAt?->toIso8601String(),
            'expires_at' => $invitation->expiresAt->toIso8601String(),
        ];
    }
}
