<?php

declare(strict_types=1);

namespace App\Actions\FrontendKeys;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Models\PublishableKey;

/**
 * Revoke a publishable key: pages still holding it stop working immediately. Revocation is a
 * timestamp, not a delete, so a key that surfaces in a log later is still identifiable as
 * one that was withdrawn, and when; revoking it again keeps the first moment.
 */
#[AsAction(
    name: 'frontend_keys.revoke',
    summary: 'Revoke a publishable frontend key. Pages still holding it stop working immediately.',
    scope: 'frontend_keys:write',
    danger: Danger::Destructive,
    tag: 'Frontend keys',
    rest: ['DELETE', '/frontend-keys/{id}'],
    status: 204,
    consoleRoutes: ['environment.keys.frontend.destroy'],
)]
final readonly class RevokeFrontendKey implements Action
{
    public function __construct(private PublishableKeys $keys) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The key\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $key = PublishableKey::query()->find($context->string('id'));

        if (! $key instanceof PublishableKey) {
            throw ActionRefused::notFound('frontend key');
        }

        $this->keys->revoke($key->id);

        return ActionResult::none($key);
    }
}
