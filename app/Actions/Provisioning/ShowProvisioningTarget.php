<?php

declare(strict_types=1);

namespace App\Actions\Provisioning;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;

/**
 * One downstream target: where it is, how the platform authenticates to it, and whether
 * its pushes are landing. Never its credential.
 */
#[AsAction(
    name: 'provisioning.targets.get',
    summary: 'Read one downstream SCIM target: its URL, auth scheme, status and last error. Never its credential.',
    scope: 'provisioning:read',
    danger: Danger::Read,
    schema: 'ProvisioningTarget',
    tag: 'Outbound provisioning',
    rest: ['GET', '/provisioning-targets/{id}'],
)]
final class ShowProvisioningTarget implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The target\'s id.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $target = ProvisioningFields::target($context);

        return ActionResult::item($target, ProvisioningFields::present($target));
    }
}
