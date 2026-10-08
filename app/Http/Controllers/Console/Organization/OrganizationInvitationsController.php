<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Organization;

use App\Http\Props\Shared\PendingInvitationProps;
use App\Http\Props\Shared\ReturnAppProps;
use App\Http\Props\Shared\RoleOptionProps;
use App\Platform\Invitations\AppReturnTargets;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use App\Platform\OrgAccessRoles;
use Inertia\Response;

/**
 * AN ORGANIZATION › INVITATIONS — invite somebody by email, and the invitations nobody has
 * accepted yet.
 *
 * The invitee accepts by the emailed link: nobody is added to an organization without
 * saying yes. Sending, resending and revoking are the `invitations.*` actions an app's
 * backend runs too, signed with this administrator's name.
 */
final readonly class OrganizationInvitationsController extends OrganizationTabController
{
    public function index(OrganizationInvitations $invitations, OrgAccessRoles $catalog, AppReturnTargets $targets): Response
    {
        $organization = $this->organization();
        $ids = ['organization' => $organization->id];

        // What an INVITATION may carry: the tenant plane's set, because accepting one is the
        // invitee's act inside the organization. Staff-only roles are granted on the member
        // once they have joined, from the Members tab.
        $inviteRoles = $catalog->tenantAssignable($organization->id);

        return $this->page('environment/organizations/tabs/invitations', $organization->name.' · Invitations', [
            'invitations' => array_map(
                static fn (PendingInvitationSummary $pending): PendingInvitationProps => PendingInvitationProps::from(
                    $pending,
                    route('environment.organizations.invitations.resend', [...$ids, 'invitation' => $pending->id]),
                    route('environment.organizations.invitations.revoke', [...$ids, 'invitation' => $pending->id]),
                ),
                $invitations->pending($organization->id),
            ),
            'inviteAccessRoles' => $this->accessRoleProps($inviteRoles, $catalog->appNames($inviteRoles)),
            // The same list the organization's own People page offers — one set of roles,
            // wherever somebody is invited from.
            'roleOptions' => RoleOptionProps::organization(),
            'apps' => array_map(ReturnAppProps::from(...), $targets->appsFor($organization->id)),
            'inviteHref' => route('environment.organizations.invitations.store', $ids),
        ]);
    }
}
