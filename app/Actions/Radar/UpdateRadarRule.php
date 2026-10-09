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
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Radar\RadarPresenter;
use App\Platform\Radar\RadarRuleInvalid;
use App\Platform\Radar\RadarRules;
use App\Platform\Radar\RadarTrail;

/** Change a Radar rule — what is sent changes; `conditions` replaces them whole. */
#[AsAction(
    name: 'radar.rules.update',
    summary: 'Change a Radar rule: its name, description, action, flows, enabled state, position, or (replaced whole) its conditions.',
    scope: 'radar:write',
    danger: Danger::Write,
    schema: 'RadarRule',
    tag: 'Radar',
    rest: ['PATCH', '/radar/rules/{id}'],
    consoleRoutes: ['environment.radar.rules.update'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class UpdateRadarRule implements Action
{
    public function __construct(
        private RadarRules $rules,
        private RadarTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(26)->describe('The rule\'s id.'),
            ...RadarRuleFields::body(creating: false),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $rule = $this->rules->find($context->string('id')) ?? throw ActionRefused::notFound('radar rule');
        $before = RadarPresenter::rule($rule);

        $changes = [];

        foreach (['name', 'description', 'conditions', 'enabled'] as $field) {
            if ($context->has($field)) {
                $changes[$field] = $field === 'enabled' ? $context->boolean('enabled') : $context->input[$field];
            }
        }

        if ($context->has('action')) {
            $changes['action'] = RadarRuleFields::action($context);
        }

        if ($context->has('applies_to')) {
            $changes['applies_to'] = RadarRuleFields::scope($context);
        }

        if (is_numeric($context->input['position'] ?? null)) {
            $changes['position'] = (int) $context->input['position'];
        }

        try {
            $changed = $this->rules->update($rule, $changes);
        } catch (RadarRuleInvalid $invalid) {
            throw ActionRefused::because('invalid_rule', $invalid->getMessage(), 'conditions');
        }

        if ($changed !== []) {
            $after = RadarPresenter::rule($rule);
            $diff = [];

            foreach ($changed as $field) {
                $diff[$field] = ['from' => $before[$field] ?? null, 'to' => $after[$field] ?? null];
            }

            $this->trail->record(RadarTrail::RULE_UPDATED, 'radar_rule', $rule->id, $context->actor(), ['changes' => $diff]);
        }

        return ActionResult::item($rule, RadarPresenter::rule($rule));
    }
}
