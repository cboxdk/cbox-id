<?php

declare(strict_types=1);

namespace App\Actions\Pipes;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;

/**
 * One pipe, with the redirect URI to register at the provider.
 */
#[AsAction(
    name: 'pipes.get',
    summary: 'Show one pipe: its provider, OAuth client id, scopes, the redirect URI to register at the provider, and the apps granted its tokens.',
    scope: 'pipes:read',
    danger: Danger::Read,
    schema: 'Pipe',
    tag: 'Pipes',
    rest: ['GET', '/pipes/{id}'],
)]
final class ShowPipe implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([PipeFields::id()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $pipe = PipeFields::pipe($context);

        return ActionResult::item($pipe, PipeFields::present($pipe));
    }
}
