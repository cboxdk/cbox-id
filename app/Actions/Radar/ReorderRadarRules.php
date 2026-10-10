<?php

declare(strict_types=1);

namespace App\Actions\Radar;

use App\Models\Radar\RadarRule;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Radar\RadarRuleInvalid;
use App\Platform\Radar\RadarRules;
use App\Platform\Radar\RadarTrail;

/**
 * Put this environment's rules in a new order — every rule, each once. The order is the
 * point of a rule list: the first match decides.
 */
#[AsAction(
    name: 'radar.rules.reorder',
    summary: 'Set the order Radar rules are evaluated in: `rule_ids` lists every rule of the environment exactly once, first evaluated first.',
    scope: 'radar:write',
    danger: Danger::Write,
    tag: 'Radar',
    rest: ['PUT', '/radar/rules/order'],
    status: 204,
    consoleRoutes: ['environment.radar.rules.order'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ReorderRadarRules implements Action
{
    public function __construct(
        private RadarRules $rules,
        private RadarTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::list('rule_ids', Field::string('id')->max(26))->required()->max(RadarRule::MAX_RULES)->describe('Every rule id, in the new order.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $ids = array_values(array_filter($context->array('rule_ids'), 'is_string'));
        $before = $this->rules->all()->modelKeys();

        try {
            $this->rules->reorder($ids);
        } catch (RadarRuleInvalid $invalid) {
            throw ActionRefused::because('invalid_order', $invalid->getMessage(), 'rule_ids');
        }

        if ($before !== $ids) {
            $this->trail->record(RadarTrail::RULES_REORDERED, 'radar_rule', null, $context->actor(), [
                'from' => $before,
                'to' => $ids,
            ]);
        }

        return ActionResult::none();
    }
}
