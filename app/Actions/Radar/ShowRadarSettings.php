<?php

declare(strict_types=1);

namespace App\Actions\Radar;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Radar\RadarPolicy;
use App\Platform\Radar\RadarPresenter;

/** Whether this environment enforces Radar, and how each built-in rule is tuned. */
#[AsAction(
    name: 'radar.settings.get',
    summary: 'Read this environment\'s Radar settings: the mode (monitor or enforce, and whether it is inherited from the deployment), the IP intelligence source, and every built-in rule with its action and threshold.',
    scope: 'radar:read',
    danger: Danger::Read,
    schema: 'RadarSettings',
    tag: 'Radar',
    rest: ['GET', '/radar/settings'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ShowRadarSettings implements Action
{
    public function __construct(private RadarPolicy $policy) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        return ActionResult::item($this->policy, RadarPresenter::settings($this->policy));
    }
}
