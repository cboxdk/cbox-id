<?php

declare(strict_types=1);

namespace App\Actions\Permissions;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Delete a manual permission — REVOKED FROM EACH ROLE first, not deleted out from under
 * them: {@see Roles::revokePermission()} writes the same rows and reports each one, so a
 * change that removes access from every holder of every role granting this key is on the
 * trail and in front of a SIEM. Fenced on each ROLE's own organization, because a role in
 * either tier may grant it.
 *
 * Any grant left on a role that no longer resolves (the pivot has no foreign key) goes
 * too, so no pair of ids outlives the permission it points at.
 */
#[AsAction(
    name: 'permissions.delete',
    summary: 'Delete a manual permission: every role carrying it loses it first.',
    scope: 'role_definitions:write',
    danger: Danger::Destructive,
    tag: 'Roles',
    rest: ['DELETE', '/permissions/{id}'],
    status: 204,
    consoleRoutes: ['permissions.destroy', 'environment.permissions.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DeletePermission implements Action
{
    public function __construct(private Roles $roles) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The permission id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $permission = PermissionFields::writable($context, $context->string('id'));

        $roleIds = DB::table('role_permission')
            ->where('permission_id', $permission->id)
            ->pluck('role_id')
            ->all();

        foreach (Role::query()->whereIn('id', $roleIds)->get() as $role) {
            $this->roles->revokePermission($role->id, $permission->id, $role->organization_id);
        }

        DB::table('role_permission')->where('permission_id', $permission->id)->delete();

        $permission->delete();

        return ActionResult::none($permission);
    }
}
