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
use App\Platform\Radar\RadarDecisions;
use App\Platform\Radar\RadarPresenter;

/** One Radar decision, explained. */
#[AsAction(
    name: 'radar.decisions.get',
    summary: 'Read one Radar decision: the verdict, whether it was enforced, the deciding rule, every rule that fired, the reasons, the risk score and the facts it was decided on.',
    scope: 'radar:read',
    danger: Danger::Read,
    schema: 'RadarDecision',
    tag: 'Radar',
    rest: ['GET', '/radar/decisions/{id}'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ShowRadarDecision implements Action
{
    public function __construct(private RadarDecisions $decisions) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(26)->describe('The decision\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $decision = $this->decisions->find($context->string('id')) ?? throw ActionRefused::notFound('radar decision');

        return ActionResult::item($decision, RadarPresenter::decision($decision, $this->decisions->ruleNames([$decision])));
    }
}
