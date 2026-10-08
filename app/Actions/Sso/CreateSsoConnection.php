<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;

/**
 * Connect an organization's identity provider — SAML or OIDC — as a DRAFT. Nobody signs in
 * through it until it is activated ({@see ActivateSsoConnection}), so a half-finished
 * connection can be saved, checked and corrected without touching anybody's sign-in.
 *
 * The owner is said out loud: one organization, or — the environment's authority only —
 * the environment itself, a connection that signs people in and enrols them nowhere. An
 * organization's connection needs its plan to include SSO. An OIDC provider is discovered
 * NOW, so a mistyped issuer fails while somebody is still looking at it.
 *
 * The certificate, client secret and signing key are input only: sealed into the
 * connection, never returned, never on the trail.
 *
 * THE SERVICE PROVIDER'S HALF IS OURS. A SAML connection's entity id and ACS URL left out are
 * this connection's own ({@see SsoFields::serviceProvider()}) — the ACS route is keyed by the
 * connection's id, so nobody could have typed it before the connection existed. And because
 * an identity provider wants those values BEFORE it hands out its own, `pending_idp` creates
 * the draft with the identity provider's half still to come: the answer's
 * `service_provider` is what to paste into the IdP, `update` fills in what it gives back,
 * and activation refuses a connection that is still missing any of it.
 */
#[AsAction(
    name: 'sso.connections.create',
    summary: 'Connect an organization\'s SAML or OIDC identity provider as a draft. Activate it to start signing people in through it.',
    scope: 'sso:write',
    danger: Danger::Write,
    schema: 'SsoConnection',
    tag: 'Single sign-on',
    rest: ['POST', '/sso/connections'],
    status: 201,
    consoleRoutes: ['connections.store', 'environment.connections.store'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class CreateSsoConnection implements Action
{
    public function __construct(
        private Connections $connections,
        private EnterpriseAudit $audit,
        private SecretBox $secretBox,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            ...EnterpriseReach::ownerFields('the connection'),
            Field::string('name')->required()->max(120)->describe('What administrators call it: "Okta", "Entra ID".'),
            Field::string('type')->required()->oneOf([ConnectionType::Saml->value, ConnectionType::Oidc->value])->describe('saml or oidc. The config fields of that type are required, unless pending_idp.'),
            Field::boolean('pending_idp')->describe('True to create the draft before the identity provider\'s details are known: the answer\'s service_provider is what to paste into it. Complete it with update before activating.'),
            ...SsoFields::configFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::owner($context, 'a connection');

        EnterpriseReach::assertEntitled($organizationId, 'sso');

        $type = ConnectionType::from($context->string('type'));
        $pending = $context->boolean('pending_idp');

        // Ours, when left out: filled in from the connection once it has an id, below.
        $config = array_filter(
            SsoFields::configFrom($context, $type),
            static fn (string $value, string $key): bool => $value !== '' || ! in_array($key, SsoFields::SERVICE_PROVIDER, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($pending) {
            // Nothing of the identity provider's yet — only what was sent, and nothing blank.
            $config = array_filter($config, static fn (string $value): bool => $value !== '');
        } else {
            SsoFields::assertComplete($config);
        }

        $connection = $this->connections->create(
            $organizationId,
            $type,
            trim($context->string('name')),
            $type === ConnectionType::Oidc && ! $pending ? SsoFields::discovered($config) : $config,
        );

        if ($type === ConnectionType::Saml) {
            $this->withServiceProvider($connection, $config);
        }

        $this->audit->record(EnterpriseAudit::SSO_CONNECTION_CREATED, $context->actor(), $organizationId, 'connection', $connection->id, [
            'name' => $connection->name,
            'type' => $type->value,
            ...$pending ? ['pending_idp' => true] : [],
        ]);

        // Re-read, so the answer carries what the columns defaulted as well as what was set.
        $connection->refresh();

        return ActionResult::item($connection, SsoFields::present($connection));
    }

    /**
     * Fill in the service provider's half this connection was created without — its own
     * entity id and ACS URL, known only now that it has an id — and re-seal. A value the
     * caller sent is kept as sent.
     *
     * @param  array<string, string>  $config
     */
    private function withServiceProvider(Connection $connection, array $config): void
    {
        $missing = array_diff_key(SsoFields::serviceProvider($connection), $config);

        if ($missing === []) {
            return;
        }

        $connection->config_encrypted = $this->secretBox->seal(json_encode([...$config, ...$missing], JSON_THROW_ON_ERROR), $connection->secretContext());
        $connection->save();
    }
}
