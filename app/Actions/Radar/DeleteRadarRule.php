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
use App\Platform\Radar\RadarRules;
use App\Platform\Radar\RadarTrail;

/** Delete a Radar rule. The rules after it move up one. */
#[AsAction(
    name: 'radar.rules.delete',
    summary: 'Delete a Radar rule. Attempts it decided are decided by the next matching rule, or the built-in rules, from then on.',
    scope: 'radar:write',
    danger: Danger::Destructive,
    tag: 'Radar',
    rest: ['DELETE', '/radar/rules/{id}'],
    status: 204,
    consoleRoutes: ['environment.radar.rules.destroy'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class DeleteRadarRule implements Action
{
    public function __construct(
        private RadarRules $rules,
        private RadarTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(26)->describe('The rule\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $rule = $this->rules->find($context->string('id')) ?? throw ActionRefused::notFound('radar rule');

        $this->rules->delete($rule);

        $this->trail->record(RadarTrail::RULE_DELETED, 'radar_rule', $rule->id, $context->actor(), [
            'name' => $rule->name,
            'action' => $rule->action->value,
            'conditions' => $rule->conditions,
        ]);

        return ActionResult::none($rule);
    }
}
