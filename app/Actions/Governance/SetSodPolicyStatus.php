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
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;

/**
 * Enforce a role-conflict rule, or stop enforcing it. The state is said (`active`)
 * rather than toggled, so a retry cannot undo itself.
 *
 * Critical: switched off, grants that break the rule are allowed again — a control the
 * organization's auditors rely on, gone. An environment-wide rule is switched through the
 * framework's unscoped call, which only the environment's own authority reaches; an
 * organization's through the scoped one, which asserts the owner too. The framework
 * records `sod.policy_activated` / `sod.policy_deactivated`.
 */
#[AsAction(
    name: 'sod_policies.status.set',
    summary: 'Enforce a role-conflict rule, or stop enforcing it — switched off, grants that break it are allowed again.',
    scope: 'governance:write',
    danger: Danger::Critical,
    schema: 'SodPolicy',
    tag: 'Governance',
    rest: ['POST', '/sod-policies/{id}/status'],
    consoleRoutes: ['sod-policies.toggle', 'environment.sod-policies.toggle'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetSodPolicyStatus implements Action
{
    public function __construct(private SegregationOfDuties $sod) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The rule\'s id.'),
            Field::boolean('active')->required()->describe('true to enforce the rule, false to stop enforcing it.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $policy = SodPolicyFields::changeable($context);
        $active = $context->boolean('active');

        if ((bool) $policy->active !== $active) {
            $policy->organization_id === null
                ? $this->sod->setActive($policy->id, $active)
                : $this->sod->setActiveForOrganization($policy->organization_id, $policy->id, $active);

            $policy->refresh();
        }

        return ActionResult::item($policy, SodPolicyFields::present($policy));
    }
}
