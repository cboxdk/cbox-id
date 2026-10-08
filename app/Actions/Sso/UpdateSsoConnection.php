<?php

declare(strict_types=1);

namespace App\Actions\Sso;

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
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;
use App\Platform\Integrations\OutboundUrl;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;

/**
 * Change a connection's name or its identity-provider settings.
 *
 * CRITICAL, because on an active connection this IS how its organization signs in: point
 * the SSO URL and certificate at another identity provider and whoever runs that provider
 * can sign in as anybody whose email routes here. Only the fields sent change; a secret
 * left out (or blank) keeps the sealed one, which is never read back to anyone. An OIDC
 * issuer is re-discovered on every change, so the endpoints can never drift from it.
 */
#[AsAction(
    name: 'sso.connections.update',
    summary: 'Change an SSO connection\'s name or identity-provider settings. Secrets left out keep the ones on file.',
    scope: 'sso:write',
    danger: Danger::Critical,
    schema: 'SsoConnection',
    tag: 'Enterprise SSO',
    rest: ['PATCH', '/sso/connections/{id}'],
    consoleRoutes: ['connections.update', 'environment.connections.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdateSsoConnection implements Action, Preflight
{
    public function __construct(
        private SecretBox $secretBox,
        private EnterpriseAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The connection\'s id.'),
            EnterpriseReach::narrowField(),
            Field::string('name')->max(120)->describe('A new name.'),
            ...SsoFields::configFields(),
        ]);
    }

    /**
     * A connection that is not this principal's, settings left incomplete, and an OIDC
     * issuer the SSRF guard refuses — before anyone approves the change. Re-discovery is a
     * fetch of the provider's server, and waits for the approval.
     */
    public function preflight(ActionContext $context): void
    {
        $connection = SsoFields::changeable($context);

        [$config] = self::merged($context, $connection->type, SsoFields::config($connection));

        if ($connection->type === ConnectionType::Oidc) {
            OutboundUrl::assertFederation($config['issuer'] ?? '', 'discovery_failed', 'issuer', SsoFields::DISCOVERY_FAILED);
        }
    }

    public function handle(ActionContext $context): ActionResult
    {
        $connection = SsoFields::changeable($context);
        $current = SsoFields::config($connection);

        [$config, $changed] = self::merged($context, $connection->type, $current);

        // An optional value neither sent nor on file is not on file as blank.
        $config = array_filter(
            $config,
            static fn (string $value, string $key): bool => $value !== '' || ! in_array($key, SsoFields::OPTIONAL, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($connection->type === ConnectionType::Oidc) {
            $config = SsoFields::discovered($config);

            // Re-discovered, the key set's address is the document's — one it no longer
            // names is not kept from before.
            unset($current['jwks_uri']);
        }

        // Everything else on file stays: the certificates staged for a rollover, the
        // logout URL, the IdP-initiated switch — none of them is a field of this form.
        $config = [...$current, ...$config];

        if ($connection->type === ConnectionType::Oidc) {
            SsoFields::assertVerifiable($config);
        }

        $name = $context->nullableString('name');

        if ($name !== null && trim($name) !== $connection->name) {
            $connection->name = trim($name);
            $changed[] = 'name';
        }

        $connection->config_encrypted = $this->secretBox->seal(json_encode($config, JSON_THROW_ON_ERROR), $connection->secretContext());
        $connection->save();

        // WHICH settings changed, never their values: three of them are secrets, and the
        // rest are worth a look at the connection rather than a copy in the trail.
        $this->audit->record(EnterpriseAudit::SSO_CONNECTION_UPDATED, $context->actor(), $connection->organization_id, 'connection', $connection->id, [
            'name' => $connection->name,
            'changed' => $changed,
        ]);

        return ActionResult::item($connection, SsoFields::present($connection));
    }

    /**
     * This type's settings once the change is made — what was sent over what is on file —
     * and which of them it changes; refused when one left blank is required.
     *
     * Only this type's keys: an OIDC field sent to a SAML connection is not a change.
     *
     * @param  array<string, mixed>  $current
     * @return array{array<string, string>, list<string>}
     *
     * @throws ActionRefused
     */
    private static function merged(ActionContext $context, ConnectionType $type, array $current): array
    {
        $config = [];
        $changed = [];

        foreach ($type === ConnectionType::Saml ? SsoFields::SAML : SsoFields::OIDC as $key) {
            $sent = trim($context->string($key));
            $stored = $current[$key] ?? null;
            $config[$key] = $sent !== '' ? $sent : (is_string($stored) ? $stored : '');

            if ($sent !== '' && $sent !== $stored) {
                $changed[] = $key;
            }
        }

        SsoFields::assertComplete($config);

        return [$config, $changed];
    }
}
