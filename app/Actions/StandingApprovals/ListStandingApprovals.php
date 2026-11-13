<?php

declare(strict_types=1);

namespace App\Actions\StandingApprovals;

use App\Models\StandingApproval;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;

/**
 * An organization's standing approvals ({@see GrantStandingApproval}): every active one, and
 * those revoked in the last {@see self::DAYS} days, newest first.
 */
#[AsAction(
    name: 'organizations.standing_approvals.list',
    summary: 'List an organization\'s standing approvals: which machine client\'s deployments to which environment of an app run without a person\'s click, who granted each, how often it was used, and those revoked in the last 30 days.',
    scope: 'organizations:read',
    danger: Danger::Read,
    schema: 'StandingApproval',
    tag: 'Organizations',
    rest: ['GET', '/organizations/{organization_id}/standing-approvals'],
)]
final readonly class ListStandingApprovals implements Action
{
    /** How far back revoked approvals are listed. */
    public const int DAYS = 30;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::requiredOrganization($context, inPath: true);

        $grants = StandingApproval::query()
            ->where('organization_id', $organizationId)
            ->where(static fn ($query) => $query->whereNull('revoked_at')->orWhere('revoked_at', '>=', now()->subDays(self::DAYS)))
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->get();

        return ActionResult::items($grants, array_values(array_map(
            static fn (StandingApproval $grant): array => StandingApprovalFields::present($grant),
            $grants->all(),
        )));
    }
}
