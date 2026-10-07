<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;

/**
 * End one of a person's sessions. Only a session belonging to THIS user resolves — a
 * session id of somebody else's, named under this user, is not found and nothing ends.
 */
#[AsAction(
    name: 'users.sessions.revoke',
    summary: 'End one of a user\'s sign-in sessions.',
    scope: 'users:write',
    danger: Danger::Destructive,
    tag: 'Users',
    rest: ['DELETE', '/users/{id}/sessions/{session_id}'],
    status: 204,
    consoleRoutes: ['environment.users.sessions.revoke'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class RevokeUserSession implements Action
{
    public function __construct(private SessionManager $sessions) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
            Field::string('session_id')->inPath()->max(64)->describe('The session id, from `GET /users/{id}/sessions`.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));

        $session = Session::query()
            ->whereKey($context->string('session_id'))
            ->where('user_id', $user->id)
            ->first() ?? throw ActionRefused::notFound('session');

        $this->sessions->revoke($session->id);

        return ActionResult::none($session);
    }
}
