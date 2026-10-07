<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\AccessControl\Contracts\Roles;
use Illuminate\Database\Eloquent\Builder;

/**
 * Put one permission on a role, so everybody who holds the role gains it — through
 * {@see Roles::attachPermission()}, audited and announced as `role.permission_granted`.
 *
 * The permission is resolved through the catalogue the caller may compose from
 * ({@see RoleAuthority::assignablePermissions()}) and must be unscoped or the role's own
 * app's: an app's privileged internal key it never marked tenant-assignable, or a key
 * another app declared, matches nothing here. Idempotent.
 *
 * The console's per-permission checkbox reaches this and {@see RevokeRolePermission} through
 * one route — an explicit state, granted or not, never a toggle a retry would flip back.
 */
#[AsAction(
    name: 'roles.permissions.grant',
    summary: 'Add a permission to a role: everyone holding the role gains it. The permission must be unscoped or declared by the role\'s own app.',
    scope: 'role_definitions:write',
    danger: Danger::Write,
    schema: 'Role',
    tag: 'Roles',
    rest: ['PUT', '/roles/{id}/permissions/{permission_id}'],
    consoleRoutes: ['roles.permissions', 'environment.roles.permissions'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class GrantRolePermission implements Action
{
    public function __construct(private Roles $roles) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The role id.'),
            Field::string('permission_id')->inPath()->max(64)->describe('The permission id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $authority = RoleAuthority::of($context->principal);
        $role = $authority->writable($context->string('id'));

        $permission = $authority->assignablePermissions()
            ->where(fn (Builder $q): Builder => $q->whereNull('client_id')->orWhere('client_id', $role->client_id))
            ->whereKey($context->string('permission_id'))
            ->first() ?? throw ActionRefused::notFound('permission');

        $this->roles->attachPermission($role->id, $permission->id, $authority->fence());

        $fresh = $role->fresh() ?? $role;

        return ActionResult::item($fresh, RoleFields::present($fresh));
    }
}
