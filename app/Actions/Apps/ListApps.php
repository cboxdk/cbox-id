<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Http\Resources\Environment\AppResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\OAuthServer\Models\Client;

#[AsAction(
    name: 'apps.list',
    summary: 'List the apps (OAuth clients) registered in this environment. Never a secret.',
    scope: 'apps:read',
    danger: Danger::Read,
    schema: 'App',
    tag: 'Applications',
    rest: ['GET', '/apps'],
)]
final class ListApps implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(Client::query(), $context, static fn (Client $client): array => AppResource::from($client));
    }
}
