<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
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
 * Sign out one of your own sessions — the browser left signed in at a borrowed laptop.
 *
 * Looked up WITH you in the query: another person's session id is not found, never
 * refused, because it is a row the caller has no business learning exists. One already
 * signed out is not found either, so asking twice is no second act. Signing out the
 * session you are holding is allowed and means what it says.
 */
#[AsAction(
    name: 'account.sessions.revoke',
    summary: 'Sign out one of your own sessions.',
    scope: 'account:sessions:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Account,
    rest: ['DELETE', '/sessions/{session_id}'],
    status: 204,
    consoleRoutes: ['account.sessions.revoke'],
    consoleGate: ConsoleGate::Person,
    tag: 'Sessions',
)]
final readonly class RevokeSession implements Action
{
    public function __construct(private SessionManager $sessions) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('session_id')->inPath(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $session = Session::query()
            ->whereKey($context->string('session_id'))
            ->where('user_id', AsPerson::subjectId($context->principal))
            ->whereNull('revoked_at')
            ->first() ?? throw ActionRefused::notFound('session');

        $this->sessions->revoke($session->id);

        return ActionResult::none($session);
    }
}
