<?php

declare(strict_types=1);

namespace App\Actions\StandingApprovals;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;
use App\Platform\StandingApprovals\StandingApprovals;
use Cbox\Id\Organization\Models\Organization;

/**
 * Turn on a standing approval: the organization's OWNER says one machine client's
 * deployments to one environment of an app run without a person's click. While it is on,
 * the token exchange issues that app a plan-bound approval token for each such deployment
 * ({@see StandingApprovals}); off, they wait for a person as every agent's change does.
 *
 * Owner only: `granted_by` must be the organization's active owner — from inside the
 * organization it is the person acting, and nobody else. Never for a production
 * environment. Granting one that is already on returns it and records nothing.
 *
 * CRITICAL: it lets a credential change what runs without anybody looking.
 *
 * Recorded as `standing_approval.granted`.
 */
#[AsAction(
    name: 'organizations.standing_approvals.grant',
    summary: 'Turn on a standing approval: the organization owner lets one machine client\'s deployments to one non-production environment of an app run without a person\'s click. Never for a production environment.',
    scope: 'organizations:write',
    danger: Danger::Critical,
    schema: 'StandingApproval',
    tag: 'Organizations',
    rest: ['POST', '/organizations/{organization_id}/standing-approvals'],
    status: 201,
)]
final readonly class GrantStandingApproval implements Action
{
    public function __construct(private StandingApprovals $approvals) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('client_id')->required()->max(191)->describe('The machine client (client credentials) of the organization whose deployments it approves.'),
            Field::string('app_client_id')->required()->max(191)->describe('The app that exchanges for the approval token (Cbox Infra\'s client).'),
            Field::string('environment_ref')->required()->max(64)->describe('The app\'s environment the deployments go to.'),
            Field::string('environment_kind')->required()->max(32)->describe('What that environment is: staging, development or another non-production kind. production is refused.'),
            Field::string('granted_by')->nullable()->max(64)->describe('The organization owner who turns it on (a user id). Required from the environment\'s keys; from inside the organization it is the person acting.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::requiredOrganization($context, inPath: true);
        $organization = Organization::query()->whereKey($organizationId)->firstOrFail();
        $person = StandingApprovalFields::assertOwnerWhenConfined($context, $organizationId);
        $named = $context->nullableString('granted_by');

        if ($person !== null && $named !== null && $named !== $person) {
            throw new ActionRefused('not_owner', 'From inside the organization, only the person acting can turn it on.', 403, 'granted_by');
        }

        $grantedBy = $person ?? $named ?? throw new ActionRefused('not_owner', 'Name the organization\'s owner who turns it on.', 422, 'granted_by');

        $grant = $this->approvals->grant(
            $organization,
            $context->string('client_id'),
            $context->string('app_client_id'),
            $context->string('environment_ref'),
            $context->string('environment_kind'),
            $grantedBy,
            $context->actor(),
        );

        return ActionResult::item($grant, StandingApprovalFields::present($grant));
    }
}
