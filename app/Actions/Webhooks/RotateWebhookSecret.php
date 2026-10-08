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
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Webhooks\Enums\SignatureScheme;
use Cbox\Id\Webhooks\Support\StandardWebhookSignature;
use Cbox\Id\Webhooks\ValueObjects\RegisteredEndpoint;

/**
 * Re-key an endpoint: a new signing secret, revealed exactly once. Deliveries signed with
 * the old one stop verifying at the receiver the moment this commits.
 *
 * The sharpest reason the endpoint is resolved within what the principal may change: run
 * against another tenant's endpoint, this hands over a live secret with which anything
 * can be forged into their receiver. The console asks for a fresh password first, because
 * scoping decides WHOSE secret this is, not whether the person at the keyboard is still
 * the administrator. The secret is in `redact`, so an idempotent replay never repeats it;
 * the trail records that a secret was issued, never the secret.
 *
 * The new secret is minted in the form the endpoint's scheme expects: 64 hex characters
 * for `cbox`, a `whsec_` secret for `standard_webhooks` — so a receiver on a Standard
 * Webhooks library takes it as is, exactly as it took the one minted at registration.
 */
#[AsAction(
    name: 'webhooks.secret.rotate',
    summary: 'Issue a new signing secret for a webhook endpoint, returned once. The old secret stops verifying immediately.',
    scope: 'webhooks:write',
    danger: Danger::Critical,
    schema: 'Webhook',
    tag: 'Webhooks',
    rest: ['POST', '/webhooks/{id}/rotate'],
    consoleRoutes: ['webhooks.rotate', 'environment.webhooks.rotate'],
    consoleGate: ConsoleGate::Administer,
    redact: ['secret'],
)]
final readonly class RotateWebhookSecret implements Action
{
    public function __construct(
        private SecretBox $secretBox,
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

        $secret = $endpoint->signature_scheme === SignatureScheme::StandardWebhooks
            ? StandardWebhookSignature::mintSecret()
            : bin2hex(random_bytes(32));
        $endpoint->secret_encrypted = $this->secretBox->seal($secret, $endpoint->secretContext());
        $endpoint->save();

        $this->audit->record(IntegrationAudit::WEBHOOK_SECRET_ROTATED, 'webhook_endpoint', $endpoint->id, $endpoint->organization_id, $context->actor(), [
            'url' => $endpoint->url,
        ]);

        return ActionResult::item(new RegisteredEndpoint($endpoint, $secret), WebhookResource::from($endpoint, $secret));
    }
}
