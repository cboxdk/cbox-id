<?php

declare(strict_types=1);

namespace App\Actions\OrgPlane\Team;

use App\Actions\OrgPlane\InOrganization;
use App\Platform\Actions\ActionMessage;
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
        $target = InOrganization::member($workspaceId, $memberId);

        if ($target->role === MembershipRole::Owner) {
            throw ActionRefused::because('not_manageable', new ActionMessage('console.the_owner_s_membership_changes_only_by_transferring_own_878454e4'));
        }

        if ($target->user_id === InOrganization::personId($principal)) {
            throw ActionRefused::because('not_manageable', new ActionMessage('console.you_cannot_change_your_own_membership_236431e4'));
        }

        return $target;
    }
}
