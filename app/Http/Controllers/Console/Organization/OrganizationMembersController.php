<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Organization;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Props\Shared\RoleOptionProps;
use App\Platform\Help\HelpTopic;
use App\Platform\OrgAccessRoles;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Membership;
use Inertia\Response;

/**
 * AN ORGANIZATION › MEMBERS — who belongs to it, at which tier, holding which app roles.
 *
 * TWO KINDS OF ACCESS ON EVERY ROW, and they answer different questions: the membership
 * role governs who administers the organization, and the access roles are what the person
 * can do inside its apps. Every write here is an action (`members.*`, `members.roles.*`,
 * `organizations.transfer_ownership`) on a route that names the organization and the
 * member, so each one re-resolves the member INSIDE the organization.
 */
final readonly class OrganizationMembersController extends OrganizationTabController
{
    public function index(Memberships $memberships, OrgAccessRoles $catalog): Response
    {
        $organization = $this->organization();

        /*
         * A PAGE OF THE ROSTER, and the names looked up for JUST that page — the cost stays
         * flat in the environment and proportional only to what is on screen.
         */
        $roster = $memberships->paginateForOrganization($organization->id, self::PER_PAGE);

        /** @var list<string> $memberIds */
        $memberIds = array_map(
            static fn (Membership $membership): string => (string) $membership->user_id,
            $roster->items(),
        );

        $users = User::query()->whereIn('id', $memberIds)->get(['id', 'name', 'email'])->keyBy('id');

        $accessRoles = $catalog->assignable($organization->id);
        $assignments = $catalog->assignmentsByUser($organization->id, $memberIds);

        return $this->page('environment/organizations/tabs/members', $organization->name.' · Members', [
            'help' => HelpProps::for(HelpTopic::Members),
            'members' => array_map(function (Membership $membership) use ($users, $assignments, $organization): array {
                $user = $users->get($membership->user_id);
                $ids = ['organization' => $organization->id, 'member' => $membership->user_id];

                return [
                    'userId' => (string) $membership->user_id,
                    'name' => $user->name ?? $user->email ?? (string) $membership->user_id,
                    'email' => $user?->email,
                    'role' => $membership->role->value,
                    'accessRoleIds' => array_values(array_filter(
                        (array) ($assignments[$membership->user_id] ?? []),
                        'is_string',
                    )),
                    'href' => route('environment.users.show', $membership->user_id),
                    'urls' => [
                        'role' => route('environment.organizations.members.role', $ids),
                        'accessRole' => route('environment.organizations.members.access', $ids),
                        'remove' => route('environment.organizations.members.remove', $ids),
                        'transfer' => route('environment.organizations.members.transfer-ownership', $ids),
                    ],
                ];
            }, $roster->items()),
            'pagination' => PaginationProps::from($roster),
            'accessRoles' => $this->accessRoleProps($accessRoles, $catalog->appNames($accessRoles)),
            // What an added member may be given — never Owner, which moves by transfer; the
            // roster's copy names Owner so an owner's row can say what it holds.
            'roleOptions' => RoleOptionProps::organization(),
            'rosterRoleOptions' => RoleOptionProps::organization(withOwner: true),
            'addMemberHref' => route('environment.organizations.members.store', ['organization' => $organization->id]),
            'invitationsHref' => route('environment.organizations.invitations', ['organization' => $organization->id]),
        ]);
    }
}
