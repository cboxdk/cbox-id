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
use App\Platform\RootSignInGuard;

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
    tag: 'Enterprise SSO',
    rest: ['DELETE', '/sso/connections/{id}'],
    status: 204,
    consoleRoutes: ['connections.destroy', 'environment.connections.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DeleteSsoConnection implements Action
{
    public function __construct(private EnterpriseAudit $audit, private RootSignInGuard $guard) {}

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

        // The platform root's own environment: nobody may leave its operators without a
        // way to sign in ({@see RootSignInGuard}). A no-op in every other environment.
        $this->guard->assertOperatorsKeepAWayIn(['connections_gone' => [(string) $connection->id]]);

        $connection->delete();

        $this->audit->record(EnterpriseAudit::SSO_CONNECTION_DELETED, $context->actor(), $connection->organization_id, 'connection', $connection->id, [
            'name' => $connection->name,
            'type' => $connection->type->value,
        ]);

        return ActionResult::none($connection);
    }
}
