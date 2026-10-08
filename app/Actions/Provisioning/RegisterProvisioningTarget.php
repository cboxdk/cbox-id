<?php

declare(strict_types=1);

namespace App\Actions\Provisioning;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;
use App\Platform\Integrations\IntegrationReach;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Cbox\Id\Provisioning\Exceptions\UnsafeScimUrl;

/**
 * Register a downstream SCIM target: from now on this platform pushes people — their
 * names, addresses, status — to a URL the caller chose.
 *
 * Critical for that reason: it is the same class of change as a webhook, with every
 * person's profile on it. An ENVIRONMENT-WIDE target receives every organization's
 * people, so only the environment's own authority may register one. The framework refuses
 * a target on a private address (SSRF); the credential the platform presents downstream
 * is sealed and never returned.
 */
#[AsAction(
    name: 'provisioning.targets.create',
    summary: 'Register a downstream app this platform pushes an organization\'s people to over SCIM, or every organization\'s with environment_wide.',
    scope: 'provisioning:write',
    danger: Danger::Critical,
    schema: 'ProvisioningTarget',
    tag: 'Outbound provisioning',
    rest: ['POST', '/provisioning-targets'],
    status: 201,
    consoleRoutes: ['provisioning.store', 'environment.provisioning.store'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RegisterProvisioningTarget implements Action
{
    public function __construct(
        private ProvisioningConnections $connections,
        private EnterpriseAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            ...EnterpriseReach::ownerFields('the target'),
            Field::string('name')->required()->max(190)->describe('What administrators call it: "Slack", "Salesforce".'),
            Field::string('base_url')->required()->max(500)->format('uri')->describe('The target\'s SCIM base URL. Must be a public address.'),
            Field::string('auth_scheme')->required()->oneOf(array_map(static fn (AuthScheme $scheme): string => $scheme->value, AuthScheme::cases()))
                ->describe('bearer (a long-lived token the app issued you) or oauth2_client_credentials (a token fetched before each batch).'),
            Field::string('secret')->required()->max(4096)->describe('The bearer token, or the OAuth client secret. Write-only.'),
            Field::string('token_url')->nullable()->max(2048)->format('uri')->describe('oauth2_client_credentials: the token endpoint.'),
            Field::string('client_id')->nullable()->max(400)->describe('oauth2_client_credentials: the client id.'),
            Field::string('scope')->nullable()->max(400)->describe('oauth2_client_credentials: the scope to ask for, if the app wants one.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::owner($context, 'a provisioning target');
        $scheme = AuthScheme::from($context->string('auth_scheme'));
        $baseUrl = trim($context->string('base_url'));

        IntegrationReach::assertUrl($baseUrl, 'base_url');

        $authConfig = [];

        if ($scheme === AuthScheme::OAuth2ClientCredentials) {
            $missing = [];

            foreach (['token_url' => 'The token URL is required.', 'client_id' => 'The client ID is required.'] as $field => $message) {
                if (trim($context->string($field)) === '') {
                    $missing[$field] = $message;
                }
            }

            if ($missing !== []) {
                throw ActionRefused::onFields('incomplete_client_credentials', $missing);
            }

            IntegrationReach::assertUrl(trim($context->string('token_url')), 'token_url');

            $authConfig = array_filter([
                'token_url' => trim($context->string('token_url')),
                'client_id' => trim($context->string('client_id')),
                'scope' => trim($context->string('scope')),
            ], static fn (string $value): bool => $value !== '');
        }

        try {
            $connection = $this->connections->register(
                $organizationId,
                trim($context->string('name')),
                $baseUrl,
                $scheme,
                $context->string('secret'),
                authConfig: $authConfig,
            )->connection;
        } catch (UnsafeScimUrl $e) {
            throw ActionRefused::because('unsafe_url', 'The SCIM base URL must be a public address. ('.$e->getMessage().')', 'base_url');
        }

        $this->audit->record(EnterpriseAudit::PROVISIONING_REGISTERED, $context->actor(), $organizationId, 'provisioning_connection', $connection->id, [
            'name' => $connection->name,
            'base_url' => $connection->base_url,
            'auth_scheme' => $scheme->value,
            'environment_wide' => $organizationId === null,
        ]);

        // Re-read, so the answer carries what the columns defaulted as well as what was set.
        $connection->refresh();

        return ActionResult::item($connection, ProvisioningFields::present($connection));
    }
}
