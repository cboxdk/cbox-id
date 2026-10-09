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

#[AsAction(
    name: 'feature_flags.get',
    summary: 'Read one feature flag: its default, whether it is switched on, and every user, organization and rollout rule.',
    scope: 'feature_flags:read',
    danger: Danger::Read,
    schema: 'FeatureFlag',
    tag: 'Feature flags',
    rest: ['GET', '/feature-flags/{id}'],
)]
final readonly class ShowFeatureFlag implements Action
{
    public function __construct(private FeatureFlags $flags) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The flag\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $flag = FeatureFlagFields::find($context, $this->flags);

        return ActionResult::item($flag, FeatureFlagFields::present($flag));
    }
}
