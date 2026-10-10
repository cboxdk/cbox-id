<?php

declare(strict_types=1);

namespace App\Actions\Pipes;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Pipes\Contracts\PipeConnections;

/**
 * Disconnect a person's account on their behalf — a leaver, a compromised account. Revoked
 * at the provider where it supports that, and in the vault always. The person can connect
 * again.
 */
#[AsAction(
    name: 'pipes.connections.delete',
    summary: 'Disconnect a person\'s connected account: revoke it at the provider where supported, revoke its tokens here, and forget it.',
    scope: 'pipes:write',
    danger: Danger::Destructive,
    tag: 'Pipes',
    rest: ['DELETE', '/pipes/{id}/connections/{connection_id}'],
    status: 204,
    consoleRoutes: ['environment.pipes.connections.destroy'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class DisconnectPipeConnection implements Action
{
    public function __construct(private PipeConnections $connections) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            PipeFields::id(),
            Field::string('connection_id')->inPath()->describe('The connection\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $connection = PipeFields::connection($context, PipeFields::pipe($context));

        $this->connections->disconnect($connection->id, actor: $context->actor());

        return ActionResult::none($connection);
    }
}
