<?php

declare(strict_types=1);

namespace App\Actions\FrontendKeys;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\FrontendApi\Models\PublishableKey;

#[AsAction(
    name: 'frontend_keys.list',
    summary: 'List the publishable frontend keys browser apps present to the Frontend API, in full, with their allowed origins.',
    scope: 'frontend_keys:read',
    danger: Danger::Read,
    schema: 'FrontendKey',
    tag: 'Publishable keys',
    rest: ['GET', '/frontend-keys'],
)]
final class ListFrontendKeys implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(PublishableKey::query()->with('origins'), $context, FrontendKeyFields::present(...));
    }
}
