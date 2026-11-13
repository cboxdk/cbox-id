<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\NativeProduct\NativeProductAccess;
use App\Platform\OAuth\OrganizationStanding;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Whether a person still holds, in a workspace, the authority keys are minted under: an
 * ACTIVE member of a workspace that is still open, whose role may change environments.
 *
 * Asked of the person behind a key before they are asked to approve its held actions
 * ({@see EnvironmentKeyPrincipal::approverSubjectId()}). An owner needs no role lookup; any
 * other member is asked through the ID product's own roles, as the console asks them.
 */
final class WorkspaceAuthority
{
    public static function mayManageEnvironments(string $subjectId, string $workspaceId): bool
    {
        if (OrganizationStanding::isClosed($workspaceId)) {
            return false;
        }

        $membership = app(PlatformRoot::class)->run(static fn () => app(Memberships::class)->of($workspaceId, $subjectId));

        if ($membership === null || $membership->status !== MembershipStatus::Active) {
            return false;
        }

        if ($membership->role === MembershipRole::Owner) {
            return true;
        }

        return app(NativeProductAccess::class)->forSubject($subjectId, $workspaceId)->canManageEnvironments();
    }
}
