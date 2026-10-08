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
use App\Platform\Integrations\IntegrationAudit;
use Cbox\Id\Webhooks\Contracts\WebhookSigningSchemes;
use Cbox\Id\Webhooks\Enums\SignatureScheme;

/**
 * Move an endpoint between signature schemes — `cbox` and `standard_webhooks` — keeping
 * its secret.
 *
 * NO NEW SECRET, AND NONE REVEALED. The owner already holds the secret, and it works under
 * either scheme: a 64-hex Cbox secret is the Standard Webhooks secret
 * `'whsec_'.base64_encode($hex)` (lossless — the decoded key is the same 64 bytes), and a
 * `whsec_` secret keys the Cbox scheme with its literal characters. So this never mints a
 * credential and never answers with one.
 *
 * DESTRUCTIVE, NOT CRITICAL — the judgement, written down. It is not Critical because
 * nothing is minted or handed over and the key is the same key: the signatures are
 * HMAC-SHA256 under the same secret either way, so the endpoint's security posture does
 * not move and there is nothing for a hijacked caller to walk away with. It is not a plain
 * Write, although another write flips it back, because the receiver's side cannot be
 * undone: from the next attempt deliveries carry the other scheme's headers, a receiver
 * still verifying the old ones rejects every one of them, and a delivery that exhausts its
 * retries in that window is gone. That is the shape of a delete — the same weight as
 * {@see DeleteWebhook}, which silences an endpoint just as surely — so MCP marks it
 * destructive and an approval policy keyed on Destructive holds it. The copy says it the
 * way it is: update the receiver FIRST.
 *
 * Acted in the endpoint's own scope, which {@see WebhookSigningSchemes::changeSignatureScheme()}
 * matches exactly, as pause does. Choosing the scheme it already has changes nothing and
 * records nothing.
 */
#[AsAction(
    name: 'webhooks.signature_scheme.change',
    summary: 'Change how a webhook endpoint\'s deliveries are signed (cbox or standard_webhooks). No new secret is issued: a hex secret is used as whsec_ + base64 of itself. Update the receiver first.',
    scope: 'webhooks:write',
    danger: Danger::Destructive,
    schema: 'Webhook',
    tag: 'Webhooks',
    rest: ['POST', '/webhooks/{id}/signature-scheme'],
    consoleRoutes: ['webhooks.scheme', 'environment.webhooks.scheme'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ChangeWebhookSignatureScheme implements Action
{
    public function __construct(
        private WebhookSigningSchemes $webhooks,
        private IntegrationAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The webhook endpoint id.'),
            Field::string('signature_scheme')->required()->oneOf(CreateWebhook::schemes())->describe('The scheme deliveries are signed with from the next attempt. The secret is unchanged: under `standard_webhooks` a 64-hex secret is used as `whsec_` + base64 of the hex string. Update the receiver before you switch.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $endpoint = WebhookEndpoints::manageable($context);
        $from = $endpoint->signature_scheme;
        $to = SignatureScheme::from($context->string('signature_scheme'));

        if ($from !== $to) {
            $endpoint = $this->webhooks->changeSignatureScheme($endpoint->id, $endpoint->organization_id, $to)
                ?? throw ActionRefused::notFound('webhook endpoint');

            $this->audit->record(IntegrationAudit::WEBHOOK_SIGNATURE_SCHEME_CHANGED, 'webhook_endpoint', $endpoint->id, $endpoint->organization_id, $context->actor(), [
                'url' => $endpoint->url,
                'changes' => ['signature_scheme' => ['from' => $from->value, 'to' => $to->value]],
            ]);
        }

        return ActionResult::item($endpoint, WebhookResource::from($endpoint));
    }
}
