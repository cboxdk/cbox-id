<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Actions\Organizations\OrganizationFields;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\OrgRoles;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Exceptions\LastOwner;

/**
 * Change a member's built-in tier — through {@see Memberships::changeRole()}, which records
 * and announces it. Ownership is not a tier this sets; demoting the last owner is refused
 * (`last_owner`): an organization with nobody in charge has nobody to hand it over or close
 * it.
 *
 * FROM INSIDE THE ORGANIZATION — its People page, or a token one of its administrators
 * signed in for — two more rules hold ({@see TenantRoster}): only an owner may change an
 * owner, and a customer's roster is administered from Workspace › Team (`managed_elsewhere`).
 */
#[AsAction(
    name: 'members.update',
    summary: 'Change a member\'s tier to `admin` or `member`. Ownership moves with transfer-ownership; the last owner cannot be demoted.',
    scope: 'members:write',
    danger: Danger::Write,
    schema: 'Member',
    tag: 'Members',
    rest: ['PATCH', '/organizations/{organization_id}/members/{user_id}'],
    consoleRoutes: ['environment.organizations.members.role', 'environment.users.organizations.role', 'directory.members.role'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ChangeMemberRole implements Action
{
    public function __construct(private Memberships $memberships) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('user_id')->inPath()->max(64)->describe('The member\'s user id.'),
            Field::string('role')->required()->oneOf(array_map(static fn (MembershipRole $role): string => $role->value, OrgRoles::assignable())),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $member = MemberFields::find($organization->id, $context->string('user_id'));

        if (TenantRoster::confined($context) !== null) {
            TenantRoster::assertMayActOn($context->principal, $organization->id, $member);
            TenantRoster::assertManagedHere($organization->id, 'role');
        }

        try {
            $membership = $this->memberships->changeRole($organization->id, $member->user_id, MembershipRole::from($context->string('role')));
        } catch (LastOwner) {
            throw new ActionRefused('last_owner', 'An organization must keep its owner. Transfer ownership to someone else first.', 409, 'role');
        }

        return ActionResult::item($membership, MemberFields::present($membership));
    }
}
