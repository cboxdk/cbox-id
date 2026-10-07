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
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\AuditStreaming\Models\AuditStream;

#[AsAction(
    name: 'log_streams.list',
    summary: 'List the SIEM destinations this environment\'s audit trail is streamed to, and whether each is enabled. Never a secret.',
    scope: 'log_streams:read',
    danger: Danger::Read,
    schema: 'LogStream',
    tag: 'Log streams',
    rest: ['GET', '/log-streams'],
    consoleGate: ConsoleGate::Administer,
)]
final class ListLogStreams implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(OwnedStreams::query($context), $context, static fn (AuditStream $stream): array => LogStreamResource::from($stream));
    }
}
