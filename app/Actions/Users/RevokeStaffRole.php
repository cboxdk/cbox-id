<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Roles\RoleFields;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Staff\Contracts\StaffRoles;

/**
 * Take a staff role back. Revoking never conflicts, and is idempotent: taking back one the
 * person does not hold is a 204 too.
 */
#[AsAction(
    name: 'users.environment_roles.revoke',
    summary: 'Take a staff role (held everywhere in this environment) back from a user.',
    scope: 'roles:write',
    danger: Danger::Destructive,
    tag: 'Roles',
    rest: ['DELETE', '/users/{id}/environment-roles/{role_id}'],
    status: 204,
    consoleRoutes: ['environment.staff.destroy'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class RevokeStaffRole implements Action
{
    public function __construct(private StaffRoles $staff) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
            Field::string('role_id')->inPath()->max(190)->describe('The role id, or its manifest `key` with `client_id`.'),
            Field::string('client_id')->max(255)->describe('The app whose manifest `key` `role_id` is.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));
        $role = RoleFields::find($context->string('role_id'), $context->nullableString('client_id'), orphaned: true);

        $this->staff->revoke($user->id, $role->id);

        return ActionResult::none($role);
    }
}
