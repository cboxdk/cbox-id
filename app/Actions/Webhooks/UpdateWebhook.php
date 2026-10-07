<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Http\Resources\Environment\WebhookResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Console\WebhookEventCatalogue;
use App\Platform\Integrations\IntegrationAudit;
use App\Platform\Integrations\IntegrationReach;
use Cbox\Id\Webhooks\Support\SafeWebhookUrl;

/**
 * Repoint an endpoint and/or change what it hears about.
 *
 * The SSRF guard runs again whenever a URL is sent: an endpoint that was public when it
 * was registered must never be silently repointed at an internal address — the registry
 * only checks at registration, so this is the check.
 *
 * WHICH events may be kept depends on the endpoint: anything offered, plus whatever it is
 * already subscribed to that no longer is ({@see WebhookEventCatalogue::forEndpoint()}),
 * so an edit never has to drop a live integration's legacy subscription to save.
 *
 * One `webhook.updated` entry with `changes: {field: {from, to}}`; nothing recorded when
 * nothing changed.
 */
#[AsAction(
    name: 'webhooks.update',
    summary: 'Change a webhook endpoint\'s URL and/or the events it subscribes to.',
    scope: 'webhooks:write',
    danger: Danger::Write,
    schema: 'Webhook',
    tag: 'Webhooks',
    rest: ['PATCH', '/webhooks/{id}'],
    consoleRoutes: ['webhooks.update', 'environment.webhooks.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdateWebhook implements Action
{
    public function __construct(private IntegrationAudit $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The webhook endpoint id.'),
            Field::string('url')->max(500)->format('uri')->describe('A public HTTPS URL. Left out, unchanged.'),
            Field::list('event_types', Field::string('event_type'))->min(1)->describe('The COMPLETE set of events afterwards. Left out, unchanged.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $endpoint = WebhookEndpoints::manageable($context);

        $before = ['url' => $endpoint->url, 'event_types' => array_values($endpoint->event_types)];
        $after = $before;

        if ($context->has('url')) {
            $after['url'] = trim($context->string('url'));

            IntegrationReach::assertUrl($after['url']);

            if (! SafeWebhookUrl::isSafe($after['url'])) {
                throw ActionRefused::because('unsafe_url', 'That URL is not allowed — it must be a public HTTPS endpoint.', 'url');
            }
        }

        if ($context->has('event_types')) {
            /** @var list<string> $eventTypes */
            $eventTypes = array_values(array_unique(array_filter($context->array('event_types'), is_string(...))));

            if (array_diff($eventTypes, WebhookEventCatalogue::forEndpoint($before['event_types'])) !== []) {
                throw ActionRefused::because('unknown_event', WebhookEventCatalogue::REFUSAL, 'event_types');
            }

            $after['event_types'] = $eventTypes;
        }

        $changes = [];

        foreach ($before as $field => $was) {
            if ($after[$field] !== $was) {
                $changes[$field] = ['from' => $was, 'to' => $after[$field]];
            }
        }

        if ($changes !== []) {
            $endpoint->url = $after['url'];
            $endpoint->event_types = $after['event_types'];
            $endpoint->save();

            $this->audit->record(IntegrationAudit::WEBHOOK_UPDATED, 'webhook_endpoint', $endpoint->id, $endpoint->organization_id, $context->actor(), [
                'changes' => $changes,
            ]);
        }

        return ActionResult::item($endpoint, WebhookResource::from($endpoint));
    }
}
