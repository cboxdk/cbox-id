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
use Cbox\Id\Federation\Enums\ConnectionStatus;

/**
 * Stop signing people in through a connection, keeping its settings to turn it back on.
 *
 * Critical: where the organization REQUIRES single sign-on, this is the door its people
 * sign in through, and closing it locks them out until it is reopened. No framework
 * service disables a connection, so the status flips on the scoped model — the same write
 * the console always made — and the trail now says who made it.
 */
#[AsAction(
    name: 'sso.connections.disable',
    summary: 'Disable an SSO connection without deleting it. Where SSO is required, its people cannot sign in until it is re-activated.',
    scope: 'sso:write',
    danger: Danger::Critical,
    schema: 'SsoConnection',
    tag: 'Enterprise SSO',
    rest: ['POST', '/sso/connections/{id}/disable'],
    consoleRoutes: ['connections.disable', 'environment.connections.disable'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DisableSsoConnection implements Action
{
    public function __construct(private EnterpriseAudit $audit) {}

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

        $connection->status = ConnectionStatus::Inactive;
        $connection->save();

        $this->audit->record(EnterpriseAudit::SSO_CONNECTION_DISABLED, $context->actor(), $connection->organization_id, 'connection', $connection->id, [
            'name' => $connection->name,
        ]);

        return ActionResult::item($connection, SsoFields::present($connection));
    }
}
