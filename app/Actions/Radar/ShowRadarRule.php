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
use App\Platform\Radar\RadarRules;

/** One of this environment's Radar rules. */
#[AsAction(
    name: 'radar.rules.get',
    summary: 'Read one Radar rule: its action, the flows it applies to, its position and its conditions.',
    scope: 'radar:read',
    danger: Danger::Read,
    schema: 'RadarRule',
    tag: 'Radar',
    rest: ['GET', '/radar/rules/{id}'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ShowRadarRule implements Action
{
    public function __construct(private RadarRules $rules) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(26)->describe('The rule\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $rule = $this->rules->find($context->string('id')) ?? throw ActionRefused::notFound('radar rule');

        return ActionResult::item($rule, RadarPresenter::rule($rule));
    }
}
