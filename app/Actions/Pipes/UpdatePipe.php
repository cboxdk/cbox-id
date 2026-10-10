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
use Cbox\Id\Pipes\Exceptions\InvalidPipeConfiguration;

/**
 * Change a pipe: a new client id or secret (re-sealed), other scopes, other parameters,
 * or switch it off. Only what is sent changes. People already connected keep the scopes
 * they consented to until they connect again; a disabled pipe refuses new connections and
 * every lease.
 */
#[AsAction(
    name: 'pipes.update',
    summary: 'Change a pipe — client id, client secret (sealed), scopes, parameters, or whether it is enabled. Only what is sent changes.',
    scope: 'pipes:write',
    danger: Danger::Write,
    schema: 'Pipe',
    tag: 'Pipes',
    rest: ['PATCH', '/pipes/{id}'],
    consoleRoutes: ['environment.pipes.update'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class UpdatePipe implements Action
{
    public function __construct(private Pipes $pipes) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            PipeFields::id(),
            Field::string('client_id')->max(500)->describe('A new OAuth client id. Left out, unchanged.'),
            Field::string('client_secret')->max(4000)->describe('A new client secret, sealed. Left out, unchanged.'),
            PipeFields::scopesField(),
            ...PipeFields::parameterFields(),
            Field::boolean('enabled')->describe('False refuses new connections and every lease. Left out, unchanged.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $pipe = PipeFields::pipe($context);

        try {
            $pipe = $this->pipes->update(
                $pipe->id,
                clientId: $context->nullableString('client_id'),
                clientSecret: $context->nullableString('client_secret'),
                scopes: $context->has('scopes') ? PipeFields::scopes($context->array('scopes')) : null,
                parameters: $context->has('parameters') ? PipeFields::parameters($context->array('parameters')) : null,
                enabled: $context->has('enabled') ? $context->boolean('enabled') : null,
                actor: $context->actor(),
            );
        } catch (InvalidPipeConfiguration $e) {
            throw PipeFields::refusal($e);
        }

        return ActionResult::item($pipe, PipeFields::present($pipe));
    }
}
