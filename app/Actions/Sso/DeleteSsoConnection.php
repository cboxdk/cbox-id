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

/**
 * Delete a connection and its sealed settings for good.
 *
 * Critical rather than merely destructive: it is a way people sign in, and an
 * organization that requires SSO through it has no other until another is activated.
 */
#[AsAction(
    name: 'sso.connections.delete',
    summary: 'Delete an SSO connection and its settings. Where SSO is required, its people cannot sign in until another is activated.',
    scope: 'sso:write',
    danger: Danger::Critical,
    tag: 'Single sign-on',
    rest: ['DELETE', '/sso/connections/{id}'],
    status: 204,
    consoleRoutes: ['connections.destroy', 'environment.connections.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DeleteSsoConnection implements Action
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

        $connection->delete();

        $this->audit->record(EnterpriseAudit::SSO_CONNECTION_DELETED, $context->actor(), $connection->organization_id, 'connection', $connection->id, [
            'name' => $connection->name,
            'type' => $connection->type->value,
        ]);

        return ActionResult::none($connection);
    }
}
