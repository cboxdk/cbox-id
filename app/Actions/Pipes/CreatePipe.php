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
use Cbox\Id\Pipes\PipeProviderCatalog;

/**
 * Offer a third-party provider to the people in this environment: the environment's OAuth
 * app at that provider (its client id, and its secret — sealed, never shown again), and
 * the scopes to ask people for. One pipe per provider.
 *
 * Nobody can lease a token through it yet: that takes a grant ({@see GrantPipeAccess}).
 */
#[AsAction(
    name: 'pipes.create',
    summary: 'Configure a pipe: the environment\'s OAuth app at a third-party provider (GitHub, Google, Microsoft 365, Slack, Salesforce, HubSpot, Linear, Notion), so people can connect their accounts. The client secret is sealed and never returned.',
    scope: 'pipes:write',
    danger: Danger::Write,
    schema: 'Pipe',
    tag: 'Pipes',
    rest: ['POST', '/pipes'],
    status: 201,
    consoleRoutes: ['environment.pipes.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class CreatePipe implements Action
{
    public function __construct(private Pipes $pipes) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('provider')->required()->oneOf(PipeProviderCatalog::keys())->describe('The provider\'s key.'),
            Field::string('client_id')->required()->max(500)->describe('The OAuth client id of your app at the provider.'),
            Field::string('client_secret')->required()->max(4000)->describe('Its client secret. Write-only: sealed, never returned.'),
            PipeFields::scopesField(),
            ...PipeFields::parameterFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        try {
            $pipe = $this->pipes->configure(
                $context->string('provider'),
                $context->string('client_id'),
                $context->string('client_secret'),
                $context->has('scopes') ? PipeFields::scopes($context->array('scopes')) : null,
                PipeFields::parameters($context->array('parameters')),
                $context->actor(),
            );
        } catch (InvalidPipeConfiguration $e) {
            throw PipeFields::refusal($e);
        }

        return ActionResult::item($pipe, PipeFields::present($pipe));
    }
}
