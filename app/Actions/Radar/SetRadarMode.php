<?php

declare(strict_types=1);

namespace App\Actions\Radar;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Radar\Enums\RadarMode;
use App\Platform\Radar\RadarPolicy;
use App\Platform\Radar\RadarPresenter;
use App\Platform\Radar\RadarTrail;

/**
 * Switch this environment between MONITOR (every verdict recorded, none acted on) and
 * ENFORCE (blocks refuse, challenges ask for a second factor).
 *
 * CRITICAL, both ways. Turning enforcement on can lock real people out of every app on the
 * environment if the rules are wrong; turning it off silently stops refusing credential
 * stuffing. Either is a change to the environment's security posture, which is what Critical
 * means here — a key with a step-up policy needs a person to approve it, and the console asks
 * for a fresh password first.
 */
#[AsAction(
    name: 'radar.mode.set',
    summary: 'Switch this environment\'s Radar between `monitor` (verdicts recorded, never acted on) and `enforce` (blocks refuse sign-ins and sign-ups, challenges demand a second factor). Changes the environment\'s security posture.',
    scope: 'radar:manage',
    danger: Danger::Critical,
    schema: 'RadarSettings',
    tag: 'Radar',
    rest: ['PUT', '/radar/mode'],
    consoleRoutes: ['environment.radar.mode.update'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class SetRadarMode implements Action
{
    public function __construct(
        private RadarPolicy $policy,
        private RadarTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('mode')->required()->oneOf(RadarMode::values())->describe('`monitor` or `enforce`.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $changes = $this->policy->setMode(RadarMode::from($context->string('mode')));

        if ($changes !== []) {
            $this->trail->record(RadarTrail::MODE_CHANGED, 'radar_settings', null, $context->actor(), ['changes' => $changes]);
        }

        return ActionResult::item($this->policy, RadarPresenter::settings($this->policy));
    }
}
