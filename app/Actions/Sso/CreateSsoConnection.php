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
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            ...EnterpriseReach::ownerFields('the connection'),
            Field::string('name')->required()->max(120)->describe('What administrators call it: "Okta", "Entra ID".'),
            Field::string('type')->required()->oneOf([ConnectionType::Saml->value, ConnectionType::Oidc->value])->describe('saml or oidc. The config fields of that type are required.'),
            ...SsoFields::configFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::owner($context, 'a connection');

        EnterpriseReach::assertEntitled($organizationId, 'sso');

        $type = ConnectionType::from($context->string('type'));
        $config = SsoFields::configFrom($context, $type);

        SsoFields::assertComplete($config);

        $connection = $this->connections->create(
            $organizationId,
            $type,
            trim($context->string('name')),
            $type === ConnectionType::Oidc ? SsoFields::discovered($config) : $config,
        );

        $this->audit->record(EnterpriseAudit::SSO_CONNECTION_CREATED, $context->actor(), $organizationId, 'connection', $connection->id, [
            'name' => $connection->name,
            'type' => $type->value,
        ]);

        // Re-read, so the answer carries what the columns defaulted as well as what was set.
        $connection->refresh();

        return ActionResult::item($connection, SsoFields::present($connection));
    }
}
