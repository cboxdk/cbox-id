<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Enterprise\EnterpriseReach;

/**
 * The role-conflict rules in force: with an organization named, its own and the
 * environment-wide ones that bind it; without, every rule in the environment.
 */
#[AsAction(
    name: 'sod_policies.list',
    summary: 'List role-conflict (segregation of duties) rules: sets of roles no one person may hold together. Optionally those binding one organization.',
    scope: 'governance:read',
    danger: Danger::Read,
    schema: 'SodPolicy',
    tag: 'Governance',
    rest: ['GET', '/sod-policies'],
)]
final class ListSodPolicies implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            EnterpriseReach::narrowField(list: true)->describe('Only the rules binding this organization: its own and the environment-wide ones.'),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(
            SodPolicyFields::visible(EnterpriseReach::narrowedTo($context)),
            $context,
            SodPolicyFields::present(...),
        );
    }
}
