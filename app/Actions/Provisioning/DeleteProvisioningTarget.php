<?php

declare(strict_types=1);

namespace App\Actions\Provisioning;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;

/**
 * Delete a downstream target and its sealed credential. Nothing more is pushed to it; the
 * people already there are the downstream app's to keep or remove.
 */
#[AsAction(
    name: 'provisioning.targets.delete',
    summary: 'Delete a downstream SCIM target. Nothing more is pushed to it.',
    scope: 'provisioning:write',
    danger: Danger::Destructive,
    tag: 'Outbound provisioning',
    rest: ['DELETE', '/provisioning-targets/{id}'],
    status: 204,
    consoleRoutes: ['provisioning.destroy', 'environment.provisioning.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DeleteProvisioningTarget implements Action
{
    public function __construct(private EnterpriseAudit $audit) {}

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

        $target->delete();

        $this->audit->record(EnterpriseAudit::PROVISIONING_DELETED, $context->actor(), $target->organization_id, 'provisioning_connection', $target->id, [
            'name' => $target->name,
            'base_url' => $target->base_url,
        ]);

        return ActionResult::none($target);
    }
}
