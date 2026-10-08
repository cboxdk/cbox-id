<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Members\TenantRoster;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Membership\AfterLeaving;
use App\Platform\Membership\MembershipLifecycle;
use App\Platform\Membership\MembershipRefusalReason;
use App\Platform\Membership\MembershipRefused;
use Cbox\Id\Organization\Contracts\Memberships;

/**
 * Leave one of your own organizations — the People page's "Leave organization".
 *
 * AN ACCOUNT ACT, NOT AN ADMINISTRATOR'S. Anybody may leave, whatever their role, so it asks
 * nothing of the console's gates; it asks only who you are, and acts on your OWN membership:
 * the organization is looked up with you in the query, so one you do not belong to is not
 * found, never refused — and once you have left, it is not found either, so asking twice is
 * no second act.
 *
 * Through {@see MembershipLifecycle::leave()}, which drops your grants with the membership
 * and records `organization.member_removed` with `reason: left`, as yours. Refused for the
 * last owner (`last_owner`), with the way out named: an organization nobody owns has nobody
 * who can hand it over or close it. A customer's roster is administered from Workspace ›
 * Team, and leaving one is refused here (`managed_elsewhere`, {@see TenantRoster}).
 *
 * Where the console then takes you — another organization of yours, or signed out when there
 * is none — is the console's ({@see AfterLeaving}); a token simply stops reaching it.
 */
#[AsAction(
    name: 'account.organizations.leave',
    summary: 'Leave one of your own organizations. The last owner cannot leave — transfer ownership first.',
    scope: 'account:organizations:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Account,
    rest: ['POST', '/organizations/{organization_id}/leave'],
    status: 204,
    consoleRoutes: ['directory.members.leave'],
    consoleGate: ConsoleGate::Person,
    tag: 'Organizations',
)]
final readonly class LeaveOrganization implements Action
{
    public function __construct(
        private MembershipLifecycle $lifecycle,
        private Memberships $memberships,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization you are leaving — one you are a member of.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $me = AsPerson::subjectId($context->principal);
        $membership = $this->memberships->of($context->string('organization_id'), $me) ?? throw ActionRefused::notFound('organization');

        TenantRoster::assertManagedHere($membership->organization_id, 'organization_id');

        try {
            $this->lifecycle->leave($membership->organization_id, $me);
        } catch (MembershipRefused $refused) {
            if ($refused->reason === MembershipRefusalReason::NotAMember) {
                throw ActionRefused::notFound('organization');
            }

            throw new ActionRefused($refused->reason->value, $refused->getMessage(), 409, 'organization_id');
        }

        return ActionResult::none($membership);
    }
}
