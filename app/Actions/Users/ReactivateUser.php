<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Identity\Contracts\Subjects;

/**
 * Let a deactivated person sign in again. The grants revoked when they were deactivated
 * stay revoked: every app they use asks them to sign in afresh. Idempotent.
 */
#[AsAction(
    name: 'users.reactivate',
    summary: 'Reactivate a deactivated user so they can sign in again. Grants revoked on deactivation stay revoked.',
    scope: 'users:write',
    danger: Danger::Write,
    schema: 'User',
    tag: 'Users',
    rest: ['POST', '/users/{id}/reactivate'],
    consoleRoutes: ['environment.users.reactivate'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ReactivateUser implements Action
{
    public function __construct(private Subjects $subjects) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));

        $this->subjects->reactivate($user->id);

        $fresh = $user->fresh() ?? $user;

        return ActionResult::item($fresh, UserFields::present($fresh));
    }
}
