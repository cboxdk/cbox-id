<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Actions\Organizations\OrganizationFields;
use App\Http\Resources\Environment\RoleResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\OrgAccessRoles;
use Cbox\Id\AccessControl\Models\Role;

/**
 * The role catalogue: every role in the environment that can still be granted (orphaned
 * roles — dropped from their app's manifest — are left out), with the permissions each
 * carries and whether tenants may grant it.
 *
 * Not paged: it is a catalogue, bounded by what apps declare and administrators define, and
 * a caller mapping its manifest keys to ids wants all of it at once. Narrow it with
 * `client_id` (one app's roles) or `organization_id` (what can be granted there).
 */
#[AsAction(
    name: 'roles.list',
    summary: 'List the roles that can be granted in this environment, with their permissions. Narrow by `client_id` (one app\'s) or `organization_id` (grantable there).',
    scope: 'roles:read',
    danger: Danger::Read,
    schema: 'Role',
    tag: 'Roles',
    rest: ['GET', '/roles'],
)]
final readonly class ListRoles implements Action
{
    public function __construct(private OrgAccessRoles $catalog) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('client_id')->max(255)->describe('Only the roles this app declared.'),
            Field::string('organization_id')->max(64)->describe('Only the roles that can be granted in this organization.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $clientId = $context->nullableString('client_id');
        $organizationId = $context->nullableString('organization_id');

        $roles = $organizationId !== null
            ? $this->catalog->assignable(OrganizationFields::find($context, $organizationId)->id)
            : Role::query()->whereNull('orphaned_at')->orderBy('name')->get();

        if ($clientId !== null) {
            $roles = $roles->filter(static fn (Role $role): bool => $role->client_id === $clientId)->values();
        }

        $permissions = $this->catalog->permissions($roles);

        return ActionResult::items($roles, array_values($roles->map(
            static fn (Role $role): array => RoleResource::from($role, $permissions[$role->id] ?? []),
        )->all()));
    }
}
