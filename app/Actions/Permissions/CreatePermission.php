<?php

declare(strict_types=1);

namespace App\Actions\Permissions;

use App\Actions\Keys\KeyFields;
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
use Cbox\Id\AccessControl\Models\Permission;
use Illuminate\Validation\ValidationException;

/**
 * Author a MANUAL permission — a `feature:action` key somebody writes here rather than an
 * app declaring it — for an organization that runs no SDK integration and still needs its
 * own vocabulary to build roles from.
 *
 * TWO TIERS, and `organization_id` says which: null is the environment's SHARED tier, which
 * every organization may compose from where `tenant_assignable` says so; an id is that one
 * organization's alone. An organization's administrator only ever writes their own — a
 * tenant's "add permission" once edited the whole environment's catalogue.
 *
 * UNIQUE among the manual permissions the author can SEE: a collision with the shared tier
 * is refused (it would put two identical keys in the role editor), and one with a peer's
 * private key is not — that would make this an existence oracle for other tenants' keys.
 * The key is lower-cased, so `Invoices:Create` is the same key as `invoices:create`.
 */
#[AsAction(
    name: 'permissions.create',
    summary: 'Author a manual `feature:action` permission, shared with the environment (organization_id null) or one organization\'s own.',
    scope: 'role_definitions:write',
    danger: Danger::Write,
    schema: 'Permission',
    tag: 'Roles',
    rest: ['POST', '/permissions'],
    status: 201,
    consoleRoutes: ['permissions.store', 'environment.permissions.store'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class CreatePermission implements Action
{
    /** feature:action — e.g. invoices:create, reports:read. */
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9_.-]*:[a-z0-9][a-z0-9_.*-]*$/i';

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(120)->describe('The key: a feature and an action joined by a colon, like invoices:create.'),
            Field::string('description')->nullable()->max(500),
            Field::string('organization_id')->nullable()->max(64)->describe('The organization it belongs to; null for the environment\'s shared tier.'),
            Field::boolean('tenant_assignable')->describe('Shared tier only: whether organizations may compose it into their own roles. Default false; always true on an organization\'s own.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $owner = OrganizationTarget::check($context, $context->nullableString('organization_id'));
        $name = mb_strtolower(trim($context->string('name')));

        if (preg_match(self::KEY_PATTERN, $name) !== 1) {
            throw ValidationException::withMessages(['name' => 'A permission key is two words joined by a colon — a feature and an action, like invoices:create.']);
        }

        $environmentId = KeyFields::environmentId($context->principal);

        $taken = Permission::query()
            ->whereNull('client_id')
            ->where('environment_id', $environmentId)
            ->visibleToOrganization($owner)
            ->where('name', $name)
            ->exists();

        if ($taken) {
            throw ActionRefused::because('permission_taken', 'A permission with that key already exists here.', 'name');
        }

        $description = trim($context->string('description'));

        $permission = Permission::query()->create([
            'client_id' => null,
            'environment_id' => $environmentId,
            'organization_id' => $owner,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            // On a row an organization owns there is nothing left to decide, so it is always
            // assignable by its owner — offering the choice would invite an administrator to
            // untick their own permission into uselessness.
            'tenant_assignable' => $owner !== null || $context->boolean('tenant_assignable'),
        ]);

        return ActionResult::item($permission, PermissionFields::present($permission->refresh()));
    }
}
