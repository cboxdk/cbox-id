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
use Cbox\Id\FeatureFlags\ValueObjects\FlagTargeting;
use Cbox\Id\FeatureFlags\ValueObjects\NewFeatureFlag;

/**
 * Define a feature flag. Live and off by default unless told otherwise, so defining one
 * changes nothing for anybody until a rule or the default turns it on. The framework
 * records `feature_flag.created` with the actor and emits the webhook event of that name.
 */
#[AsAction(
    name: 'feature_flags.create',
    summary: 'Define a feature flag: a key apps ask about, its default, and who it is on for — named users, named organizations, a rollout percentage.',
    scope: 'feature_flags:write',
    danger: Danger::Write,
    schema: 'FeatureFlag',
    tag: 'Feature flags',
    rest: ['POST', '/feature-flags'],
    status: 201,
    consoleRoutes: ['environment.feature-flags.store'],
)]
final readonly class CreateFeatureFlag implements Action
{
    public function __construct(private FeatureFlags $flags) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('key')->required()->max(64)->describe('What code asks for and the `feature_flags` claim carries: lowercase letters and digits, with `-`, `_` or `.` between them. Fixed once created.'),
            Field::string('description')->nullable()->max(500),
            Field::boolean('enabled')->describe('The kill switch: off means off for everyone, whatever the rules say. Defaults to true.'),
            Field::boolean('default_value')->describe('The answer when no rule matches. Defaults to false.'),
            ...FeatureFlagFields::targetingFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        try {
            $flag = $this->flags->create(new NewFeatureFlag(
                key: $context->string('key'),
                description: $context->nullableString('description'),
                enabled: $context->boolean('enabled', true),
                defaultValue: $context->boolean('default_value'),
                targeting: FeatureFlagFields::targeting($context, FlagTargeting::none()),
            ), $context->actor());
        } catch (InvalidFeatureFlag $invalid) {
            throw FeatureFlagFields::refusal($invalid);
        }

        return ActionResult::item($flag, FeatureFlagFields::present($flag));
    }
}
