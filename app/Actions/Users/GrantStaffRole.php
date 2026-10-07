<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Roles\RoleFields;
use App\Http\Resources\Environment\RoleAssignmentResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Staff\Contracts\StaffRoles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Models\EnvironmentRoleAssignment;

/**
 * Grant a STAFF role: a role held everywhere in this environment, in every organization the
 * person belongs to (and by them when they belong to none). An app's own role granted this
 * way reaches only that app's tokens.
 *
 * Through {@see StaffRoles}, the console's one door for these, so segregation of duties is
 * asked one way whichever door asks: against the staff roles the person already holds, and
 * in every organization they are in — and a refusal names the policy, the role it collides
 * with and where. Only a role no organization owns may be granted so; an organization's own
 * role, or an orphaned one, is refused by name. Idempotent.
 *
 * CRITICAL: a staff role is the environment's own authority over every customer at once —
 * an app's `support:impersonate` held this way signs its holder in as any of them.
 */
#[AsAction(
    name: 'users.environment_roles.grant',
    summary: 'Grant a user a staff role: held everywhere in this environment, in every organization. Name the role by id, or by manifest `key` with `client_id`.',
    scope: 'roles:write',
    danger: Danger::Critical,
    schema: 'RoleAssignment',
    tag: 'Roles',
    rest: ['PUT', '/users/{id}/environment-roles/{role_id}'],
    consoleRoutes: ['environment.users.roles', 'environment.staff.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class GrantStaffRole implements Action
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
        $role = RoleFields::find($context->string('role_id'), $context->nullableString('client_id'));

        // Asked of the database, never of a page: a posted id is anything a client sends.
        if (! $this->staff->isGrantable($role->id)) {
            throw ActionRefused::because('role_not_assignable', "The role [{$role->name}] belongs to one organization and cannot be granted everywhere.", 'role_id');
        }

        $refusal = $this->staff->grant($user->id, $role->id);

        if ($refusal !== null) {
            throw new ActionRefused('role_conflict', $refusal->message(), 409, 'role_id');
        }

        $source = EnvironmentRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->value('source');

        return ActionResult::item($role, RoleAssignmentResource::from(
            $role,
            $user->id,
            null,
            $source instanceof GrantSource ? $source : (GrantSource::tryFrom(is_string($source) ? $source : '') ?? GrantSource::Manual),
        ));
    }
}
