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
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Exceptions\LastOwner;

/**
 * Remove a member — and every access role they held in the organization, which the framework
 * drops with the membership and announces as `membership.deleted`. Refused for the last
 * owner (`last_owner`).
 */
#[AsAction(
    name: 'members.remove',
    summary: 'Remove a member from an organization, with every role they held there. The last owner cannot be removed.',
    scope: 'members:write',
    danger: Danger::Destructive,
    tag: 'Members',
    rest: ['DELETE', '/organizations/{organization_id}/members/{user_id}'],
    status: 204,
    consoleRoutes: ['environment.organizations.members.remove', 'environment.users.organizations.remove'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class RemoveMember implements Action
{
    public function __construct(private Memberships $memberships) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('user_id')->inPath()->max(64)->describe('The member\'s user id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $member = MemberFields::find($organization->id, $context->string('user_id'));

        try {
            $this->memberships->remove($organization->id, $member->user_id);
        } catch (LastOwner) {
            throw new ActionRefused('last_owner', 'An organization must keep its owner. Transfer ownership to someone else first.', 409, 'user_id');
        }

        return ActionResult::none($member);
    }
}
