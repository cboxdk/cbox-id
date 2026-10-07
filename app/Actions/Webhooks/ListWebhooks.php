<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Http\Resources\Environment\WebhookResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Integrations\IntegrationReach;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;

/**
 * Every webhook endpoint in this environment — or, for an organization's administrator,
 * their own and the environment's, which receive their events. Never a signing secret.
 */
#[AsAction(
    name: 'webhooks.list',
    summary: 'List the webhook endpoints registered in this environment: URL, owner, subscribed events and whether each is active.',
    scope: 'webhooks:read',
    danger: Danger::Read,
    schema: 'Webhook',
    tag: 'Webhooks',
    rest: ['GET', '/webhooks'],
    consoleGate: ConsoleGate::Administer,
)]
final class ListWebhooks implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        $query = IntegrationReach::visible(WebhookEndpoint::query(), IntegrationReach::confinedTo($context->principal));

        return $this->page($query, $context, static fn (WebhookEndpoint $endpoint): array => WebhookResource::from($endpoint));
    }
}
