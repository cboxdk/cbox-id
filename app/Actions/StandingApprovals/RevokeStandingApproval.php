<?php

declare(strict_types=1);

namespace App\Actions\StandingApprovals;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\StandingApprovals\StandingApprovals;

/**
 * Turn a standing approval off: from the next deployment on, that client's deployments wait
 * for a person again; an approval token already issued lives out its few minutes.
 *
 * From inside the organization only its owner turns one off. The environment's authority
 * (its console, its keys) may always turn one off — taking a "yes" back is never gated on
 * being allowed to give it, and an app turns one off by itself when the environment became
 * production (`reason: environment_kind_changed`). Turning off one already off changes
 * nothing and records nothing.
 *
 * Recorded as `standing_approval.revoked`.
 */
#[AsAction(
    name: 'organizations.standing_approvals.revoke',
    summary: 'Turn a standing approval off: that machine client\'s deployments to that environment wait for a person again.',
    scope: 'organizations:write',
    danger: Danger::Destructive,
    tag: 'Organizations',
    rest: ['DELETE', '/organizations/{organization_id}/standing-approvals/{id}'],
    status: 204,
    consoleRoutes: ['environment.organizations.standing-approvals.revoke'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RevokeStandingApproval implements Action
{
    public function __construct(private StandingApprovals $approvals) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('id')->inPath()->max(64)->describe('The standing approval\'s id.'),
            Field::string('reason')->nullable()->max(64)->describe('Why, as a short code (for example environment_kind_changed). Kept on the audit log.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $grant = StandingApprovalFields::find($context);
        StandingApprovalFields::assertOwnerWhenConfined($context, $grant->organization_id);

        $this->approvals->revoke($grant, $context->actor(), $context->nullableString('reason'));

        return ActionResult::none($grant);
    }
}
