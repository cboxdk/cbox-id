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
 * Start a paused endpoint again — the action whose absence turns a reversible pause into
 * a one-way door.
 *
 * {@see WebhookRegistry} has no resume, so this is a status write on the endpoint already
 * resolved within what the principal may change. Resuming an active endpoint changes
 * nothing and records nothing.
 */
#[AsAction(
    name: 'webhooks.resume',
    summary: 'Resume a paused webhook endpoint: it receives events again.',
    scope: 'webhooks:write',
    danger: Danger::Write,
    schema: 'Webhook',
    tag: 'Webhooks',
    rest: ['POST', '/webhooks/{id}/resume'],
    consoleRoutes: ['webhooks.resume', 'environment.webhooks.resume'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ResumeWebhook implements Action
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

        if ($endpoint->status !== EndpointStatus::Active) {
            $endpoint->status = EndpointStatus::Active;
            $endpoint->save();

            $this->audit->record(IntegrationAudit::WEBHOOK_RESUMED, 'webhook_endpoint', $endpoint->id, $endpoint->organization_id, $context->actor(), [
                'url' => $endpoint->url,
            ]);
        }

        return ActionResult::item($endpoint, WebhookResource::from($endpoint));
    }
}
