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
 * Stop a person signing in — a soft disable, never a delete. Deleting people is not
 * something an API key does, and the console has no delete either: the schema carries no
 * foreign key on `user_id`, so a row delete left sessions, factors, tokens and the person's
 * whole IdP profile behind and told the administrator an erasure had happened.
 *
 * DESTRUCTIVE, because the framework also revokes every grant the person holds (an OAuth
 * refresh token outliving its owner is the case deprovisioning is bought for), and
 * reactivating them does not bring those back. Idempotent: deactivating somebody already
 * deactivated changes and records nothing, and answers with them as they are.
 */
#[AsAction(
    name: 'users.deactivate',
    summary: 'Deactivate a user: they can no longer sign in, and every OAuth grant they hold is revoked. Never deletes them.',
    scope: 'users:write',
    danger: Danger::Destructive,
    schema: 'User',
    tag: 'Users',
    rest: ['DELETE', '/users/{id}'],
    consoleRoutes: ['environment.users.deactivate'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class DeactivateUser implements Action
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

        $this->subjects->deactivate($user->id);

        $fresh = $user->fresh() ?? $user;

        return ActionResult::item($fresh, UserFields::present($fresh));
    }
}
