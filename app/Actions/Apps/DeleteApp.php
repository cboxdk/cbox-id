<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;

/**
 * Delete an app and every secret it holds. Whatever signs in as it stops working at once,
 * so it is critical: undoing it means registering a new app with a new client id and
 * redeploying everything that held the old one. The registry records `app.deleted`.
 */
#[AsAction(
    name: 'apps.delete',
    summary: 'Delete an app and every secret it holds. Anything signing in as it stops working immediately.',
    scope: 'apps:write',
    danger: Danger::Critical,
    tag: 'Apps',
    rest: ['DELETE', '/apps/{id}'],
    status: 204,
    consoleRoutes: ['clients.destroy', 'environment.clients.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DeleteApp implements Action
{
    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([AppFields::id()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));

        $this->clients->delete($client, $context->actor());

        return ActionResult::none($client);
    }
}
