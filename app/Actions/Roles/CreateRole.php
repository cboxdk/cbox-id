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
use App\Platform\Actions\OrganizationTarget;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Permission;

/**
 * Define a role — what people may do inside the apps — with its opening permissions, through
 * {@see Roles}, so the definition and every permission on it are audited and announced.
 *
 * WHOSE ROLE IT IS is `organization_id`: one organization's own, or — null — the whole
 * environment's, assignable inside every tenant. Only the environment's authority may define
 * one of those ({@see RoleAuthority}); an organization's administrator defines roles for
 * their own organization and no other.
 *
 * `client_id` scopes it to one app's tokens, and must be an app in reach of its owner.
 * Every permission is resolved against the catalogue the caller may compose from, and must
 * be unscoped or the role's own app's: a key another app declared never lands on it.
 */
#[AsAction(
    name: 'roles.create',
    summary: 'Define a role for one organization, or (organization_id null) for the whole environment, optionally scoped to one app, with its opening permissions.',
    scope: 'role_definitions:write',
    danger: Danger::Write,
    schema: 'Role',
    tag: 'Roles',
    rest: ['POST', '/roles'],
    status: 201,
    consoleRoutes: ['roles.store', 'environment.roles.store'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class CreateRole implements Action
{
    public function __construct(private Roles $roles) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(120),
            Field::string('description')->nullable()->max(500),
            Field::string('organization_id')->nullable()->max(64)->describe('The organization it belongs to; null defines it for every organization in the environment.'),
            Field::string('client_id')->nullable()->max(190)->describe('The app whose tokens it is stamped into; null for every app.'),
            Field::list('permissions', Field::string('permission')->max(64))->max(200)->describe('Permission ids to grant it from the start.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $authority = RoleAuthority::of($context->principal);
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));

        $clientId = $context->nullableString('client_id');

        if ($clientId !== null && ! array_key_exists($clientId, $authority->usableApps($organizationId))) {
            throw ActionRefused::because('app_not_usable', 'That app is not one this role\'s organization can use.', 'client_id');
        }

        $ids = array_values(array_unique(array_filter($context->array('permissions'), 'is_string')));
        $permissions = $ids === [] ? [] : $authority->assignablePermissions()
            ->whereKey($ids)
            ->get(['id', 'client_id'])
            ->filter(static fn (Permission $permission): bool => $permission->client_id === null || $permission->client_id === $clientId)
            ->all();

        if (count($permissions) !== count($ids)) {
            throw ActionRefused::because('permission_not_assignable', 'One or more of those permissions does not exist, or cannot be put on this role.', 'permissions');
        }

        $role = $this->roles->define($organizationId, trim($context->string('name')), $this->description($context), $clientId);

        foreach ($permissions as $permission) {
            $this->roles->attachPermission($role->id, $permission->id, $organizationId);
        }

        $fresh = $role->fresh() ?? $role;

        return ActionResult::item($fresh, RoleFields::present($fresh));
    }

    private function description(ActionContext $context): ?string
    {
        $description = trim($context->string('description'));

        return $description !== '' ? $description : null;
    }
}
