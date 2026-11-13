<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Lifecycle\EndOtherSessions;

/**
 * Sign out every one of your sessions but the one you are using — the account-wide lever.
 *
 * CRITICAL, and behind a fresh password in the console, because of its blast radius: it is
 * exactly what somebody at a borrowed, unlocked laptop would pull to lock the real owner
 * out while keeping the tab in front of them. From a delegated token that is no session,
 * "every other" is every one.
 *
 * The apps those sessions signed in to lose their grants with them ({@see EndOtherSessions}):
 * ending the sessions alone left an app somebody authorized from one of them refreshing.
 *
 * Answers how many were signed out; asked again, that is none.
 */
#[AsAction(
    name: 'account.sessions.revoke_others',
    summary: 'Sign out every one of your sessions except the one you are using.',
    scope: 'account:sessions:write',
    danger: Danger::Critical,
    plane: ActionPlane::Account,
    rest: ['POST', '/sessions/revoke-others'],
    consoleRoutes: ['account.sessions.revoke-others'],
    consoleGate: ConsoleGate::Person,
    schema: 'SessionsRevoked',
    tag: 'Sessions',
)]
final readonly class RevokeOtherSessions implements Action
{
    public function __construct(private EndOtherSessions $sessions) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $revoked = $this->sessions->handle(
            AsPerson::subjectId($context->principal),
            AsPerson::currentSessionId($context->principal),
        );

        return ActionResult::item($revoked, ['revoked' => $revoked]);
    }
}
