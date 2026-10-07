<?php

declare(strict_types=1);

namespace App\Actions\LogStreams;

use App\Http\Resources\Environment\LogStreamResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;

#[AsAction(
    name: 'log_streams.get',
    summary: 'Get one audit log stream: destination, endpoint, auth scheme, owner and health. Never its secret.',
    scope: 'log_streams:read',
    danger: Danger::Read,
    schema: 'LogStream',
    tag: 'Log streams',
    rest: ['GET', '/log-streams/{id}'],
    consoleGate: ConsoleGate::Administer,
)]
final class ShowLogStream implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The log stream id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $stream = OwnedStreams::find($context);

        return ActionResult::item($stream, LogStreamResource::from($stream));
    }
}
