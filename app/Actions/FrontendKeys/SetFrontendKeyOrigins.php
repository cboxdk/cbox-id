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
use Cbox\Id\FrontendApi\Exceptions\UnusableOrigin;
use Cbox\Id\FrontendApi\Models\PublishableKey;

/**
 * Replace a key's allow-list.
 *
 * THE ALLOW-LIST IS THE CONTROL, and a control that can only be set once is not one: adding
 * a staging domain otherwise meant minting a second key and shipping a new bundle. Exactly
 * the list given, all or nothing. A revoked key's list decides nothing, so changing one is
 * refused rather than accepted as a change that looks like it took effect.
 */
#[AsAction(
    name: 'frontend_keys.set_origins',
    summary: 'Replace the exact list of origins allowed to present a publishable frontend key. Live on the next request.',
    scope: 'frontend_keys:write',
    danger: Danger::Write,
    schema: 'FrontendKey',
    tag: 'Frontend keys',
    rest: ['PUT', '/frontend-keys/{id}/origins'],
    consoleRoutes: ['environment.keys.frontend.origins'],
)]
final readonly class SetFrontendKeyOrigins implements Action
{
    public function __construct(private PublishableKeys $keys) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The key\'s id.'),
            FrontendKeyFields::origins()->required(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $key = PublishableKey::query()->find($context->string('id'));

        if (! $key instanceof PublishableKey) {
            throw ActionRefused::notFound('frontend key');
        }

        if (! $key->isActive()) {
            throw ActionRefused::because('key_revoked', 'This key is revoked; its origins decide nothing. Create a new key instead.', 'origins');
        }

        try {
            $this->keys->setOrigins($key->id, CreateFrontendKey::strings($context->array('origins')));
        } catch (UnusableOrigin $e) {
            throw ActionRefused::because('unusable_origin', $e->getMessage(), 'origins');
        }

        $fresh = $key->fresh(['origins']) ?? $key;

        return ActionResult::item($fresh, FrontendKeyFields::present($fresh));
    }
}
