<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;

/**
 * One role and the permissions it carries. Named by id, or by manifest `key` with
 * `client_id`.
 */
#[AsAction(
    name: 'roles.get',
    summary: 'Get one role and the permissions it carries. Name it by id, or by manifest `key` with `client_id`.',
    scope: 'roles:read',
    danger: Danger::Read,
    schema: 'Role',
    tag: 'Roles',
    rest: ['GET', '/roles/{id}'],
)]
final readonly class ShowRole implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(190)->describe('The role id, or its manifest `key` with `client_id`.'),
            Field::string('client_id')->max(255)->describe('The app whose manifest `key` `id` is.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $role = RoleFields::find($context->string('id'), $context->nullableString('client_id'));

        return ActionResult::item($role, RoleFields::present($role));
    }
}
