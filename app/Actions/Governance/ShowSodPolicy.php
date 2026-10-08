<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;

/**
 * One role-conflict rule: the roles it forbids together and whether it is enforced.
 */
#[AsAction(
    name: 'sod_policies.get',
    summary: 'Read one role-conflict rule: the roles no one person may hold together, and whether it is enforced.',
    scope: 'governance:read',
    danger: Danger::Read,
    schema: 'SodPolicy',
    tag: 'Governance',
    rest: ['GET', '/sod-policies/{id}'],
)]
final class ShowSodPolicy implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The rule\'s id.'),
            EnterpriseReach::narrowField()->describe('Only if it binds this organization (its own, or environment-wide); anything else is a 404.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $policy = SodPolicyFields::readable($context);

        return ActionResult::item($policy, SodPolicyFields::present($policy));
    }
}
