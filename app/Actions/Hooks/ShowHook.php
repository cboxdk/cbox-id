<?php

declare(strict_types=1);

namespace App\Actions\Hooks;

use App\Http\Resources\Environment\InlineHookResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;

#[AsAction(
    name: 'hooks.get',
    summary: 'Get one hook: its URL, hook point, owner and whether it is active. Never its signing secret.',
    scope: 'hooks:read',
    danger: Danger::Read,
    schema: 'InlineHook',
    tag: 'Hooks',
    rest: ['GET', '/hooks/{id}'],
    consoleGate: ConsoleGate::Administer,
)]
final class ShowHook implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The hook id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $endpoint = HookEndpoints::visible($context);

        return ActionResult::item($endpoint, InlineHookResource::from($endpoint));
    }
}
