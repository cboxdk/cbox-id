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
 * Rename a role, or change its description — through {@see Roles::updateRole()}, which
 * records the change.
 *
 * Not its app scope and not its organization: both decide which tokens the role reaches and
 * which tenants may hold it, and moving a role between them after people hold it is a
 * silent change to who has access. Delete and re-create is the honest path. A role an app
 * declared is that app's, and refused as forbidden.
 */
#[AsAction(
    name: 'roles.update',
    summary: 'Rename a role or change its description. An app-declared role is changed in the app\'s manifest instead.',
    scope: 'role_definitions:write',
    danger: Danger::Write,
    schema: 'Role',
    tag: 'Roles',
    rest: ['PATCH', '/roles/{id}'],
    consoleRoutes: ['roles.update', 'environment.roles.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdateRole implements Action
{
    public function __construct(private Roles $roles) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The role id.'),
            Field::string('name')->min(1)->max(120),
            Field::string('description')->nullable()->max(500),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $authority = RoleAuthority::of($context->principal);
        $role = $authority->writable($context->string('id'));

        $name = $context->has('name') ? trim($context->string('name')) : $role->name;
        $description = $context->has('description') ? trim($context->string('description')) : $role->description;

        $updated = $this->roles->updateRole($role->id, $name, $description === '' ? null : $description, $authority->fence());

        return ActionResult::item($updated, RoleFields::present($updated));
    }
}
