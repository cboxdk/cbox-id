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
use App\Platform\Actions\Preflight;
use App\Platform\Console\WebhookEventCatalogue;
use App\Platform\Integrations\IntegrationAudit;
use App\Platform\Integrations\IntegrationReach;
use App\Platform\Integrations\OutboundUrl;
use Cbox\Id\Webhooks\Contracts\WebhookSigningSchemes;
use Cbox\Id\Webhooks\Enums\SignatureScheme;
use Cbox\Id\Webhooks\Exceptions\UnsafeWebhookUrl;

/**
 * Register a webhook endpoint and hand over its signing secret — once.
 *
 * CRITICAL, not merely a write: it mints the credential the receiver verifies with, and
 * starts shipping this environment's events to an address the caller chose. The console
 * asks for a fresh password in front of it; a management key is its own credential.
 *
 * The secret is in `redact`: the first answer carries it, an idempotent replay of that
 * answer carries null. A caller that lost it rotates; a table of plaintext signing secrets
 * kept for retries would be worth more than the endpoints they sign for.
 *
 * Its owner is said out loud ({@see IntegrationReach::owner()}): an organization, or
 * `environment_wide` — an endpoint that receives every organization's events — which an
 * organization's administrator may never create.
 *
 * `signature_scheme` picks how its deliveries are signed ({@see SignatureScheme}): `cbox`,
 * the default and the only scheme there was before it could be chosen, or
 * `standard_webhooks`, which any Standard Webhooks library verifies — and whose secret is
 * minted as a `whsec_` secret so that library takes it as is. Registered through
 * {@see WebhookSigningSchemes}, the framework's contract for choosing it; the plain
 * registry would always mean `cbox`.
 */
#[AsAction(
    name: 'webhooks.create',
    summary: 'Register a webhook endpoint for one organization or the whole environment. Returns its signing secret once.',
    scope: 'webhooks:write',
    danger: Danger::Critical,
    schema: 'Webhook',
    tag: 'Webhooks',
    rest: ['POST', '/webhooks'],
    status: 201,
    consoleRoutes: ['webhooks.store', 'environment.webhooks.store'],
    consoleGate: ConsoleGate::Administer,
    redact: ['secret'],
)]
final readonly class CreateWebhook implements Action, Preflight
{
    public function __construct(
        private WebhookSigningSchemes $webhooks,
        private IntegrationAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('url')->required()->max(500)->format('uri')->describe('A public HTTPS URL. Signed deliveries are POSTed here.'),
            Field::list('event_types', Field::string('event_type')->oneOf(WebhookEventCatalogue::offered()))->required()->min(1)->describe('The events it receives.'),
            Field::string('signature_scheme')->oneOf(self::schemes())->describe('How deliveries are signed: `cbox` (X-Cbox-Signature, the default) or `standard_webhooks` (webhook-id / webhook-timestamp / webhook-signature, verifiable with any Standard Webhooks library; the secret is a `whsec_` secret).'),
            ...IntegrationReach::ownerFields(),
        ]);
    }

    /**
     * The schemes an endpoint may be registered with, as the wire names them.
     *
     * @return list<string>
     */
    public static function schemes(): array
    {
        return array_map(static fn (SignatureScheme $scheme): string => $scheme->value, SignatureScheme::cases());
    }

    /**
     * Whose it is and whether the URL may be dialled — before a key's owner is asked to
     * approve an endpoint the SSRF guard would then refuse.
     */
    public function preflight(ActionContext $context): void
    {
        IntegrationReach::owner($context);
        OutboundUrl::assertWebhook(trim($context->string('url')));
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = IntegrationReach::owner($context);
        $url = trim($context->string('url'));
        $scheme = SignatureScheme::from($context->nullableString('signature_scheme') ?? SignatureScheme::Cbox->value);

        IntegrationReach::assertUrl($url);

        /** @var list<string> $eventTypes */
        $eventTypes = array_values(array_unique(array_filter($context->array('event_types'), is_string(...))));

        try {
            // Two calls, never one with a nullable argument: "every tenant's events" is
            // stated at the one call site entitled to make it.
            $registered = $organizationId === null
                ? $this->webhooks->registerForEnvironmentWithScheme($url, $eventTypes, $scheme)
                : $this->webhooks->registerWithScheme($organizationId, $url, $eventTypes, $scheme);
        } catch (UnsafeWebhookUrl) {
            throw ActionRefused::because('unsafe_url', 'That URL is not allowed — it must be a public HTTPS endpoint.', 'url');
        }

        // Read back, so the columns the database defaulted (health, sequence) are real.
        $endpoint = $registered->endpoint->refresh();

        $this->audit->record(IntegrationAudit::WEBHOOK_CREATED, 'webhook_endpoint', $endpoint->id, $endpoint->organization_id, $context->actor(), [
            'url' => $endpoint->url,
            'event_types' => array_values($endpoint->event_types),
            'signature_scheme' => $endpoint->signature_scheme->value,
        ]);

        return ActionResult::item($registered, WebhookResource::from($endpoint, $registered->secret));
    }
}
