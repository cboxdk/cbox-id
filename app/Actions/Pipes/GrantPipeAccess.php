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
 * Let an app of this environment lease the tokens people connect through a pipe.
 * CRITICAL: from now on that app can act as any connected person at the provider, within
 * the scopes they consented to.
 */
#[AsAction(
    name: 'pipes.grants.create',
    summary: 'Grant an app (by OAuth client id) the right to lease fresh access tokens for the accounts people connected through this pipe.',
    scope: 'pipes:write',
    danger: Danger::Critical,
    schema: 'Pipe',
    tag: 'Pipes',
    rest: ['POST', '/pipes/{id}/grants'],
    consoleRoutes: ['environment.pipes.grants.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class GrantPipeAccess implements Action
{
    public function __construct(private Pipes $pipes) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            PipeFields::id(),
            Field::string('client_id')->required()->max(190)->describe('The OAuth client id of an app in this environment.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $pipe = PipeFields::pipe($context);
        $clientId = trim($context->string('client_id'));

        PipeFields::assertApp($clientId);

        $this->pipes->grant($pipe->id, $clientId, $context->actor());

        return ActionResult::item($pipe, PipeFields::present($pipe));
    }
}
