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
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Integrations\IntegrationAudit;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Enums\EndpointStatus;

/**
 * Stop delivering to an endpoint, keeping it — {@see ResumeWebhook} starts it again.
 *
 * Acted in the endpoint's OWN scope, which is what the registry matches on: passing the
 * endpoint's organization (null for the environment's own) is the one call that resolves
 * for an environment administrator and an organization's alike. Pausing a paused endpoint
 * changes nothing and records nothing.
 */
#[AsAction(
    name: 'webhooks.pause',
    summary: 'Pause a webhook endpoint: it stops receiving events until resumed.',
    scope: 'webhooks:write',
    danger: Danger::Write,
    schema: 'Webhook',
    tag: 'Webhooks',
    rest: ['POST', '/webhooks/{id}/pause'],
    consoleRoutes: ['webhooks.pause', 'environment.webhooks.pause'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class PauseWebhook implements Action
{
    public function __construct(
        private WebhookRegistry $webhooks,
        private IntegrationAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The webhook endpoint id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $endpoint = WebhookEndpoints::manageable($context);

        if ($endpoint->status !== EndpointStatus::Paused) {
            $this->webhooks->pause($endpoint->id, $endpoint->organization_id);
            $endpoint->refresh();

            $this->audit->record(IntegrationAudit::WEBHOOK_PAUSED, 'webhook_endpoint', $endpoint->id, $endpoint->organization_id, $context->actor(), [
                'url' => $endpoint->url,
            ]);
        }

        return ActionResult::item($endpoint, WebhookResource::from($endpoint));
    }
}
