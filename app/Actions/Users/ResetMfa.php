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
use Cbox\Id\Identity\Contracts\Mfa;

/**
 * Take away a person's second factor, so they enrol again at their next sign-in — through
 * {@see Mfa::disable()}, audited as `user.mfa_disabled` with whoever asked as the actor.
 *
 * CRITICAL: it leaves the account protected by its password alone, which is the step that
 * makes a stolen password worth having.
 */
#[AsAction(
    name: 'users.mfa.reset',
    summary: 'Reset a user\'s two-factor authentication: their authenticator and recovery codes are removed and they must enrol again.',
    scope: 'users:write',
    danger: Danger::Critical,
    tag: 'Users',
    rest: ['DELETE', '/users/{id}/mfa'],
    status: 204,
    consoleRoutes: ['environment.users.mfa'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ResetMfa implements Action
{
    public function __construct(private Mfa $mfa) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));
        $actor = $context->actor();

        $this->mfa->disable($user->id, $actor->type, $actor->id);

        return ActionResult::none($user);
    }
}
