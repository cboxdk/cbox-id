<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\AccessControl\Contracts\Roles;

/**
 * Delete a role — and with it every grant of it, from everybody who holds it. Through
 * {@see Roles::deleteRole()}, so the change to every holder's access is audited and
 * announced (`role.unassigned` for the apps that mirror grants off it). A role an app
 * declared is that app's, and refused as forbidden.
 */
#[AsAction(
    name: 'roles.delete',
    summary: 'Delete a role: everyone who holds it loses it. An app-declared role is removed from the app\'s manifest instead.',
    scope: 'role_definitions:write',
    danger: Danger::Destructive,
    tag: 'Roles',
    rest: ['DELETE', '/roles/{id}'],
    status: 204,
    consoleRoutes: ['roles.destroy', 'environment.roles.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DeleteRole implements Action
{
    public function __construct(private Roles $roles) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The role id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $authority = RoleAuthority::of($context->principal);
        $role = $authority->writable($context->string('id'));

        $this->roles->deleteRole($role->id, $authority->fence());

        return ActionResult::none($role);
    }
}
