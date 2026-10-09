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
use Cbox\Id\Pipes\Contracts\Pipes;

/**
 * Take back an app's right to lease a pipe's tokens. Takes effect on its next lease; a
 * token it already holds works until the provider expires it.
 */
#[AsAction(
    name: 'pipes.grants.delete',
    summary: 'Withdraw an app\'s right to lease the tokens people connected through this pipe.',
    scope: 'pipes:write',
    danger: Danger::Destructive,
    tag: 'Pipes',
    rest: ['DELETE', '/pipes/{id}/grants/{client_id}'],
    status: 204,
    consoleRoutes: ['environment.pipes.grants.destroy'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class RevokePipeAccess implements Action
{
    public function __construct(private Pipes $pipes) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            PipeFields::id(),
            Field::string('client_id')->inPath()->max(190)->describe('The OAuth client id whose grant is withdrawn.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $pipe = PipeFields::pipe($context);

        $this->pipes->revokeGrant($pipe->id, $context->string('client_id'), $context->actor());

        return ActionResult::none($pipe);
    }
}
