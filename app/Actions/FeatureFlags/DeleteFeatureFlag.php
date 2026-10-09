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

/**
 * Delete a flag and its rules. Code still asking for its key gets off, and tokens already
 * minted keep naming it until they expire.
 */
#[AsAction(
    name: 'feature_flags.delete',
    summary: 'Delete a feature flag and its rules. Its key evaluates to off from now on; tokens already minted keep it until they expire.',
    scope: 'feature_flags:write',
    danger: Danger::Destructive,
    tag: 'Feature flags',
    rest: ['DELETE', '/feature-flags/{id}'],
    status: 204,
    consoleRoutes: ['environment.feature-flags.destroy'],
)]
final readonly class DeleteFeatureFlag implements Action
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

        $this->flags->delete($flag->id, $context->actor());

        return ActionResult::none($flag);
    }
}
