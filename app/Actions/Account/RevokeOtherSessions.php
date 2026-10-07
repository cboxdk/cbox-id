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
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sign out every one of your sessions but the one you are using — the account-wide lever.
 *
 * CRITICAL, and behind a fresh password in the console, because of its blast radius: it is
 * exactly what somebody at a borrowed, unlocked laptop would pull to lock the real owner
 * out while keeping the tab in front of them. From a delegated token that is no session,
 * "every other" is every one.
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
    public function __construct(private SessionManager $sessions) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $current = AsPerson::currentSessionId($context->principal);
        $revoked = 0;

        Session::query()
            ->where('user_id', AsPerson::subjectId($context->principal))
            ->whereNull('revoked_at')
            ->when($current !== null, fn (Builder $query): Builder => $query->whereKeyNot($current))
            ->pluck('id')
            ->each(function (mixed $id) use (&$revoked): void {
                if (is_string($id)) {
                    $this->sessions->revoke($id);
                    $revoked++;
                }
            });

        return ActionResult::item($revoked, ['revoked' => $revoked]);
    }
}
