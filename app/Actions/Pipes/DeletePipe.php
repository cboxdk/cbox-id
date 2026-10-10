<?php

declare(strict_types=1);

namespace App\Actions\Pipes;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Pipes\Contracts\Pipes;

/**
 * Remove a pipe, its grants and every connection through it. The connections' tokens are
 * revoked in the vault so nothing here can present them again; they are not revoked at the
 * provider (one outbound call per person is not something one request can promise to
 * finish), which the summary says so nobody is surprised.
 */
#[AsAction(
    name: 'pipes.delete',
    summary: 'Remove a pipe and every connection through it. Their tokens are revoked here, not at the provider — disconnect people first if that matters.',
    scope: 'pipes:write',
    danger: Danger::Destructive,
    tag: 'Pipes',
    rest: ['DELETE', '/pipes/{id}'],
    status: 204,
    consoleRoutes: ['environment.pipes.destroy'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class DeletePipe implements Action
{
    public function __construct(private Pipes $pipes) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([PipeFields::id()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $pipe = PipeFields::pipe($context);

        $this->pipes->remove($pipe->id, $context->actor());

        return ActionResult::none($pipe);
    }
}
