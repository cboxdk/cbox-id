<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Integrations\IntegrationAudit;

/**
 * Delete an endpoint: nothing more is delivered to it. Undoing it means registering a new
 * one, with a new secret the receiver has to be given.
 *
 * The entry keeps the URL and the subscription: once the row is gone, the trail is the
 * only place that says where this environment's events were being sent.
 */
#[AsAction(
    name: 'webhooks.delete',
    summary: 'Delete a webhook endpoint. It stops receiving events at once.',
    scope: 'webhooks:write',
    danger: Danger::Destructive,
    tag: 'Webhooks',
    rest: ['DELETE', '/webhooks/{id}'],
    status: 204,
    consoleRoutes: ['webhooks.destroy', 'environment.webhooks.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DeleteWebhook implements Action
{
    public function __construct(private IntegrationAudit $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The webhook endpoint id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $endpoint = WebhookEndpoints::manageable($context);

        $endpoint->delete();

        $this->audit->record(IntegrationAudit::WEBHOOK_DELETED, 'webhook_endpoint', $endpoint->id, $endpoint->organization_id, $context->actor(), [
            'url' => $endpoint->url,
            'event_types' => array_values($endpoint->event_types),
        ]);

        return ActionResult::none($endpoint);
    }
}
