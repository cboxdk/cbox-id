<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;

/**
 * One user of this environment. An id from another environment is not found.
 */
#[AsAction(
    name: 'users.get',
    summary: 'Get one user of this environment by id.',
    scope: 'users:read',
    danger: Danger::Read,
    schema: 'User',
    tag: 'Users',
    rest: ['GET', '/users/{id}'],
)]
final readonly class ShowUser implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));

        return ActionResult::item($user, UserFields::present($user));
    }
}
