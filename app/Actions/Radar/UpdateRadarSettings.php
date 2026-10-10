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
use App\Platform\Radar\Enums\RadarBuiltin;
use App\Platform\Radar\RadarPolicy;
use App\Platform\Radar\RadarPresenter;
use App\Platform\Radar\RadarRuleInvalid;
use App\Platform\Radar\RadarTrail;

/**
 * Tune the built-in rules: switch one on or off, give it another action, move its threshold.
 *
 * Only the rules named change. The mode is NOT here — switching enforcement on or off is its
 * own, critical action ({@see SetRadarMode}), so a key that may tune thresholds cannot also
 * turn protection off.
 */
#[AsAction(
    name: 'radar.settings.update',
    summary: 'Tune this environment\'s built-in Radar rules: `builtin_rules` maps a rule key (credential_stuffing, bot_velocity, account_attack, impossible_travel, new_device, anonymous_network, hosting_network, disposable_email, risk_score_reject, risk_score_elevated) to any of enabled, action and threshold.',
    scope: 'radar:write',
    danger: Danger::Write,
    schema: 'RadarSettings',
    tag: 'Radar',
    rest: ['PATCH', '/radar/settings'],
    consoleRoutes: ['environment.radar.settings.update'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class UpdateRadarSettings implements Action
{
    public function __construct(
        private RadarPolicy $policy,
        private RadarTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::object('builtin_rules', [])->required()->describe('Rule key => `{"enabled": bool, "action": "allow"|"challenge"|"block", "threshold": int}`, each part optional. Keys: '.implode(', ', RadarBuiltin::values()).'.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        try {
            $changes = $this->policy->updateBuiltins($context->array('builtin_rules'));
        } catch (RadarRuleInvalid $invalid) {
            throw ActionRefused::because('invalid_setting', $invalid->getMessage(), 'builtin_rules');
        }

        if ($changes !== []) {
            $this->trail->record(RadarTrail::BUILTINS_UPDATED, 'radar_settings', null, $context->actor(), ['changes' => $changes]);
        }

        return ActionResult::item($this->policy, RadarPresenter::settings($this->policy));
    }
}
