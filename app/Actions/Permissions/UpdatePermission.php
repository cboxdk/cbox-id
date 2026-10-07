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

/**
 * Change a manual permission's description, and — on the shared tier — whether
 * organizations may compose it into their own roles.
 *
 * NOT the key. Roles are composed of permissions by id, but an app reading `permissions` out
 * of a token matches on the STRING — so renaming one silently changes what every deployed
 * app checks against. Delete and re-create is the honest path.
 */
#[AsAction(
    name: 'permissions.update',
    summary: 'Change a manual permission\'s description, and on the shared tier whether organizations may use it. The key itself never changes.',
    scope: 'role_definitions:write',
    danger: Danger::Write,
    schema: 'Permission',
    tag: 'Roles',
    rest: ['PATCH', '/permissions/{id}'],
    consoleRoutes: ['permissions.update', 'environment.permissions.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdatePermission implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The permission id.'),
            Field::string('description')->nullable()->max(500),
            Field::boolean('tenant_assignable')->describe('Shared tier only: whether organizations may compose it into their own roles.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $permission = PermissionFields::writable($context, $context->string('id'));

        if ($context->has('description')) {
            $description = trim($context->string('description'));
            $permission->description = $description !== '' ? $description : null;
        }

        // An organization's own row has one possible answer, so a posted claim about it is
        // not read.
        if ($permission->organization_id === null && $context->has('tenant_assignable')) {
            $permission->tenant_assignable = $context->boolean('tenant_assignable');
        }

        $permission->save();

        return ActionResult::item($permission, PermissionFields::present($permission));
    }
}
