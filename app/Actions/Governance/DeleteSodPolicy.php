<?php

declare(strict_types=1);

namespace App\Actions\Governance;

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
 * Remove a role-conflict rule for good. Critical for the reason switching one off is: the
 * grants it refused are allowed from the moment it is gone. No framework service removes a
 * rule, so the scoped model is deleted — as the console always did — and the trail now
 * says who did it.
 */
#[AsAction(
    name: 'sod_policies.delete',
    summary: 'Remove a role-conflict rule. Grants it refused are allowed from then on.',
    scope: 'governance:write',
    danger: Danger::Critical,
    tag: 'Governance',
    rest: ['DELETE', '/sod-policies/{id}'],
    status: 204,
    consoleRoutes: ['sod-policies.destroy', 'environment.sod-policies.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DeleteSodPolicy implements Action
{
    public function __construct(private EnterpriseAudit $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The rule\'s id.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $policy = SodPolicyFields::changeable($context);

        $policy->delete();

        $this->audit->record(EnterpriseAudit::SOD_POLICY_DELETED, $context->actor(), $policy->organization_id, 'sod_policy', $policy->id, [
            'name' => $policy->name,
            'role_ids' => array_values(array_filter($policy->role_ids, 'is_string')),
        ]);

        return ActionResult::none($policy);
    }
}
