<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Members\MemberFields;
use App\Actions\Members\TenantRoster;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Membership\MembershipLifecycle;
use App\Platform\Membership\MembershipRefusalReason;
use App\Platform\Membership\MembershipRefused;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Make a member the organization's owner, from OUTSIDE the organization.
 *
 * The environment's authority is nobody inside it, so there is no outgoing owner to name:
 * every current owner steps down to admin and the trail records
 * `organization.ownership_transferred` as whoever asked — the key, or the administrator.
 * That is also how an organization created without an owner gets its first, and how one
 * that collected several owners before ownership became transfer-only is tidied to one.
 * The same {@see MembershipLifecycle} the console's "Make owner" has always used.
 *
 * FROM INSIDE THE ORGANIZATION — its People page, or a token its owner signed in for — it
 * is the owner's own hand-over ({@see TenantRoster}): only the owner may make it, they stay
 * on as an admin, and the trail records it as theirs. Handing it to yourself changes
 * nothing. A customer's roster is administered from Workspace › Team (`managed_elsewhere`).
 *
 * CRITICAL: it hands the organization — its people, its apps, the right to close it — to
 * somebody else.
 */
#[AsAction(
    name: 'organizations.transfer_ownership',
    summary: 'Make a member the owner of an organization. Every current owner stays on as an admin; from inside the organization, only its owner may hand it over.',
    scope: 'organizations:write',
    danger: Danger::Critical,
    schema: 'Member',
    tag: 'Organizations',
    rest: ['POST', '/organizations/{id}/transfer-ownership'],
    consoleRoutes: ['environment.organizations.members.transfer-ownership', 'directory.members.transfer-ownership'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class TransferOwnership implements Action
{
    public function __construct(
        private MembershipLifecycle $lifecycle,
        private Memberships $memberships,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('user_id')->required()->max(64)->describe('The member who becomes the owner — an active member already.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('id'));
        $to = $context->string('user_id');
        $from = null;

        if (TenantRoster::confined($context) !== null) {
            if (! TenantRoster::isOwner($context->principal, $organization->id)) {
                throw new AuthorizationException('Only the owner can hand the organization over.');
            }

            TenantRoster::assertManagedHere($organization->id, 'user_id');

            $from = TenantRoster::personId($context->principal);

            // Already theirs: nothing to hand over, and nothing to refuse either.
            if ($to === $from) {
                $membership = $this->memberships->of($organization->id, $to) ?? throw ActionRefused::notFound('member');

                return ActionResult::item($membership, MemberFields::present($membership));
            }
        }

        try {
            $this->lifecycle->transferOwnership($organization->id, $to, $from, $from ?? $context->actor()->id);
        } catch (MembershipRefused $refused) {
            throw new ActionRefused(
                $refused->reason->value,
                $refused->getMessage(),
                $refused->reason === MembershipRefusalReason::NotAMember ? 422 : 409,
                'user_id',
            );
        }

        $membership = $this->memberships->of($organization->id, $to) ?? throw ActionRefused::notFound('member');

        return ActionResult::item($membership, MemberFields::present($membership));
    }
}
