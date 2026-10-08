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

/**
 * Take one permission off a role, so everybody who holds the role loses it — through
 * {@see Roles::revokePermission()}, audited and announced. Idempotent: taking off one the
 * role does not carry changes nothing. A permission this environment cannot see at all —
 * another environment's, or none — is a 404, as an unknown id is everywhere: "nothing
 * changed" would be an answer about somebody else's permission.
 */
#[AsAction(
    name: 'roles.permissions.revoke',
    summary: 'Remove a permission from a role: everyone holding the role loses it.',
    scope: 'role_definitions:write',
    danger: Danger::Destructive,
    schema: 'Role',
    tag: 'Roles',
    rest: ['DELETE', '/roles/{id}/permissions/{permission_id}'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RevokeRolePermission implements Action
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

        // Any permission this environment can see — one that has since been orphaned, or
        // left the catalogue this caller composes from, must still be removable.
        $permission = $authority->visiblePermissions()->whereKey($context->string('permission_id'))->first()
            ?? throw ActionRefused::notFound('permission');

        $this->roles->revokePermission($role->id, $permission->id, $authority->fence());

        $fresh = $role->fresh() ?? $role;

        return ActionResult::item($fresh, RoleFields::present($fresh));
    }
}
