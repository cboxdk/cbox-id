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
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Integrations\IntegrationReach;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;

/**
 * Every hook in this environment — or, for an organization's administrator, their
 * own and the environment's, which fire on their sign-ins. Never a signing secret.
 */
#[AsAction(
    name: 'hooks.list',
    summary: 'List the hooks — endpoints called during sign-in and token issuance — with their hook point, owner and whether each is active.',
    scope: 'hooks:read',
    danger: Danger::Read,
    schema: 'InlineHook',
    tag: 'Hooks',
    rest: ['GET', '/hooks'],
    consoleGate: ConsoleGate::Administer,
)]
final class ListHooks implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        $query = IntegrationReach::visible(ExternalActionEndpoint::query(), IntegrationReach::confinedTo($context->principal));

        return $this->page($query, $context, static fn (ExternalActionEndpoint $endpoint): array => InlineHookResource::from($endpoint));
    }
}
