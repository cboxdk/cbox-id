<?php

declare(strict_types=1);

namespace App\Actions\Keys;

use App\Http\Resources\Environment\ManagementKeyResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\Platform\Models\EnvironmentApiKey;

#[AsAction(
    name: 'keys.list',
    summary: 'List this environment\'s management keys (names, scopes, parents, expiry, last use — never their values).',
    scope: 'keys:read',
    danger: Danger::Read,
    tag: 'Secret keys',
    rest: ['GET', '/keys'],
)]
final class ListKeys implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(EnvironmentApiKey::query(), $context, static fn (EnvironmentApiKey $key): array => ManagementKeyResource::from($key));
    }
}
