<?php

declare(strict_types=1);

namespace App\Actions\FeatureFlags;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\FeatureFlags\Models\FeatureFlag;

#[AsAction(
    name: 'feature_flags.list',
    summary: 'List this environment\'s feature flags with their default, kill switch and targeting rules.',
    scope: 'feature_flags:read',
    danger: Danger::Read,
    schema: 'FeatureFlag',
    tag: 'Feature flags',
    rest: ['GET', '/feature-flags'],
)]
final class ListFeatureFlags implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(FeatureFlag::query()->with('targets'), $context, FeatureFlagFields::present(...));
    }
}
