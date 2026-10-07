<?php

declare(strict_types=1);

namespace App\Actions\Apis;

use App\Http\Resources\Environment\ApiResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\OAuthServer\Models\Api;

#[AsAction(
    name: 'apis.list',
    summary: 'List the APIs (resource servers) registered in this environment, with their scopes.',
    scope: 'apis:read',
    danger: Danger::Read,
    schema: 'Api',
    tag: 'APIs',
    rest: ['GET', '/apis'],
)]
final class ListApis implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(Api::query()->with('scopes'), $context, ApiResource::from(...));
    }
}
