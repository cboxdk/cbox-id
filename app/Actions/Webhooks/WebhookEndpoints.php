<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Integrations\IntegrationReach;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;

/**
 * The endpoint a webhook action names, resolved within what its principal may see —
 * shared by every webhook action, and not one itself.
 *
 * Re-resolved on every call rather than trusted from a route binding: an id from another
 * environment is outside the model's environment scope, one from another organization is
 * outside {@see IntegrationReach::visible()}, and both answer 404 — the caller was not
 * entitled to learn it exists.
 */
final class WebhookEndpoints
{
    /** @throws ActionRefused */
    public static function visible(ActionContext $context): WebhookEndpoint
    {
        $endpoint = IntegrationReach::visible(WebhookEndpoint::query(), IntegrationReach::confinedTo($context->principal))
            ->whereKey($context->string('id'))
            ->first();

        return $endpoint ?? throw ActionRefused::notFound('webhook endpoint');
    }

    /**
     * The endpoint, refused unless this principal may CHANGE it: an organization's
     * administrator sees the environment's own endpoint and may not touch it.
     *
     * @throws ActionRefused
     */
    public static function manageable(ActionContext $context): WebhookEndpoint
    {
        $endpoint = self::visible($context);

        IntegrationReach::assertManageable($endpoint->organization_id, IntegrationReach::confinedTo($context->principal), 'this webhook endpoint');

        return $endpoint;
    }
}
