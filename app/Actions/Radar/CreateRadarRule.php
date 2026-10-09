<?php

declare(strict_types=1);

namespace App\Actions\Radar;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarRuleScope;
use App\Platform\Radar\RadarPresenter;
use App\Platform\Radar\RadarRuleInvalid;
use App\Platform\Radar\RadarRules;
use App\Platform\Radar\RadarTrail;

/**
 * Write a Radar rule of this environment's own: when every condition holds, allow, challenge
 * or block. Evaluated after the allow and deny lists and before the built-in rules, in order;
 * the first that matches decides.
 */
#[AsAction(
    name: 'radar.rules.create',
    summary: 'Add a Radar rule: when EVERY condition (field, operator, value) holds, allow, challenge or block. Rules run in order after the allow/deny lists and before the built-in rules; the first match decides. Example: country not_in [DK, SE] → challenge.',
    scope: 'radar:write',
    danger: Danger::Write,
    schema: 'RadarRule',
    tag: 'Radar',
    rest: ['POST', '/radar/rules'],
    status: 201,
    consoleRoutes: ['environment.radar.rules.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class CreateRadarRule implements Action
{
    public function __construct(
        private RadarRules $rules,
        private RadarTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of(RadarRuleFields::body(creating: true));
    }

    public function handle(ActionContext $context): ActionResult
    {
        $position = $context->input['position'] ?? null;

        try {
            $rule = $this->rules->create(
                $context->string('name'),
                $context->nullableString('description'),
                RadarRuleFields::action($context) ?? RadarAction::Challenge,
                RadarRuleFields::scope($context) ?? RadarRuleScope::All,
                RadarRuleFields::conditions($context),
                $context->boolean('enabled', true),
                is_numeric($position) ? (int) $position : null,
            );
        } catch (RadarRuleInvalid $invalid) {
            throw ActionRefused::because('invalid_rule', $invalid->getMessage(), 'conditions');
        }

        $this->trail->record(RadarTrail::RULE_CREATED, 'radar_rule', $rule->id, $context->actor(), [
            'name' => $rule->name,
            'action' => $rule->action->value,
            'applies_to' => $rule->applies_to->value,
            'position' => $rule->position,
            'conditions' => $rule->conditions,
        ]);

        return ActionResult::item($rule, RadarPresenter::rule($rule));
    }
}
