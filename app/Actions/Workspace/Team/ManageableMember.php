<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Team;

use App\Actions\Workspace\InWorkspace;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\Principal;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Membership;

/**
 * The member a team write is about, IF it may be managed: one of THIS workspace's members
 * (else not found), not the owner — whose role and access change only by transferring
 * ownership — and not the person acting, who does not re-role or remove themselves from
 * the roster. A helper, not an action.
 */
final class ManageableMember
{
    /** @throws ActionRefused */
    public static function resolve(Principal $principal, string $workspaceId, string $memberId): Membership
    {
        $target = InWorkspace::member($workspaceId, $memberId);

        if ($target->role === MembershipRole::Owner) {
            throw ActionRefused::because('not_manageable', 'The owner\'s membership changes only by transferring ownership.');
        }

        if ($target->user_id === InWorkspace::personId($principal)) {
            throw ActionRefused::because('not_manageable', 'You cannot change your own membership.');
        }

        return $target;
    }
}
