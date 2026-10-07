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

#[AsAction(
    name: 'webhooks.get',
    summary: 'Get one webhook endpoint: URL, owner, subscribed events, health. Never its signing secret.',
    scope: 'webhooks:read',
    danger: Danger::Read,
    schema: 'Webhook',
    tag: 'Webhooks',
    rest: ['GET', '/webhooks/{id}'],
    consoleGate: ConsoleGate::Administer,
)]
final class ShowWebhook implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The webhook endpoint id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $endpoint = WebhookEndpoints::visible($context);

        return ActionResult::item($endpoint, WebhookResource::from($endpoint));
    }
}
