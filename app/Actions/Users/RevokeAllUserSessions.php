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
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;

/**
 * Sign a person out everywhere — "this account's access is over until they sign in again".
 *
 * THE GRANTS TOO, not only the sessions. Ending the sessions tells every app the person
 * signed in to (Back-Channel Logout), but a refresh token each app held went on minting
 * access tokens, so an app that ignored the logout carried on acting as them.
 * {@see RefreshTokens::withdrawAccess()} revokes those first and tells the apps holding
 * them; the session revocation after it then finds nobody left to notify twice.
 */
#[AsAction(
    name: 'users.sessions.revoke_all',
    summary: 'Sign a user out everywhere: end every session and revoke every OAuth grant they hold.',
    scope: 'users:write',
    danger: Danger::Destructive,
    tag: 'Users',
    rest: ['DELETE', '/users/{id}/sessions'],
    status: 204,
    consoleRoutes: ['environment.users.sessions.revoke-all'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class RevokeAllUserSessions implements Action
{
    public function __construct(
        private SessionManager $sessions,
        private RefreshTokens $grants,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));

        $this->grants->withdrawAccess($user->id);
        $this->sessions->revokeAllForUser($user->id);

        return ActionResult::none($user);
    }
}
