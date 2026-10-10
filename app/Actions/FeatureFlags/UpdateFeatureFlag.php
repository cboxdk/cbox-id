<?php

declare(strict_types=1);

namespace App\Actions\FeatureFlags;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\FeatureFlags\Contracts\FeatureFlags;
use Cbox\Id\FeatureFlags\Exceptions\InvalidFeatureFlag;
use Cbox\Id\FeatureFlags\ValueObjects\FeatureFlagChanges;

/**
 * Change a flag: its description, its kill switch, its default, and any part of its
 * targeting. Only what is sent changes; each targeting part sent replaces that part whole.
 * A change that changes nothing is neither recorded nor announced.
 */
#[AsAction(
    name: 'feature_flags.update',
    summary: 'Change a feature flag: switch it on or off for everyone, change its default, or replace its user rules, organization rules or rollout percentage.',
    scope: 'feature_flags:write',
    danger: Danger::Write,
    schema: 'FeatureFlag',
    tag: 'Feature flags',
    rest: ['PATCH', '/feature-flags/{id}'],
    consoleRoutes: ['environment.feature-flags.update'],
)]
final readonly class UpdateFeatureFlag implements Action
{
    public function __construct(private FeatureFlags $flags) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The flag\'s id.'),
            Field::string('description')->nullable()->max(500)->describe('Null or empty clears it.'),
            Field::boolean('enabled')->describe('The kill switch: off means off for everyone.'),
            Field::boolean('default_value')->describe('The answer when no rule matches.'),
            ...FeatureFlagFields::targetingFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $flag = FeatureFlagFields::find($context, $this->flags);

        $changes = FeatureFlagChanges::make();

        if ($context->has('description')) {
            $changes = $changes->withDescription($context->nullableString('description'));
        }

        if ($context->has('enabled')) {
            $changes = $changes->withEnabled($context->boolean('enabled'));
        }

        if ($context->has('default_value')) {
            $changes = $changes->withDefaultValue($context->boolean('default_value'));
        }

        if (FeatureFlagFields::sendsTargeting($context)) {
            $changes = $changes->withTargeting(FeatureFlagFields::targeting($context, $flag->targeting()));
        }

        try {
            $updated = $this->flags->update($flag->id, $changes, $context->actor());
        } catch (InvalidFeatureFlag $invalid) {
            throw FeatureFlagFields::refusal($invalid);
        }

        return ActionResult::item($updated, FeatureFlagFields::present($updated));
    }
}
