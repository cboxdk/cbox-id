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
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;

/**
 * Start signing people in through a connection. The framework scopes the switch to the
 * connection's own organization — so a draft cannot be activated across tenants — and
 * records `connection.activated` itself.
 *
 * Activation does not REQUIRE single sign-on: passwords keep working until somebody asks
 * for that separately ({@see RequireSso}), because tightening it ends every password
 * session in the organization.
 */
#[AsAction(
    name: 'sso.connections.activate',
    summary: 'Activate an SSO connection: people whose email domain routes to it start signing in through it.',
    scope: 'sso:write',
    danger: Danger::Critical,
    schema: 'SsoConnection',
    tag: 'Single sign-on',
    rest: ['POST', '/sso/connections/{id}/activate'],
    consoleRoutes: ['connections.activate', 'environment.connections.activate'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ActivateSsoConnection implements Action
{
    public function __construct(private Connections $connections) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The connection\'s id.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $connection = SsoFields::changeable($context);

        // A draft created before its identity provider's details were known would route
        // everybody at its domains to a sign-in that cannot work.
        if (in_array($connection->type, [ConnectionType::Saml, ConnectionType::Oidc], true)
            && ! SsoFields::isComplete($connection->type, SsoFields::config($connection))) {
            throw ActionRefused::because('incomplete_connection', 'This connection is missing its identity provider\'s details. Complete it before activating.');
        }

        $this->connections->activate($connection->organization_id, $connection->id);

        $fresh = $this->connections->byId($connection->id) ?? $connection;

        return ActionResult::item($fresh, SsoFields::present($fresh));
    }
}
