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

#[AsAction(
    name: 'apps.get',
    summary: 'Get one app (OAuth client) by its id or its client_id: its kind, grants, redirect URIs, scopes and settings. Never a secret.',
    scope: 'apps:read',
    danger: Danger::Read,
    schema: 'App',
    tag: 'Apps',
    rest: ['GET', '/apps/{id}'],
)]
final class ShowApp implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([AppFields::id()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));

        return ActionResult::item($client, AppResource::from($client));
    }
}
