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
use Cbox\Id\Identity\Models\Session;

/**
 * A person's live sign-in sessions, most recently active first — enough to recognise a
 * device and end one, not a log. The fifty most recent, as the console shows.
 */
#[AsAction(
    name: 'users.sessions.list',
    summary: 'List a user\'s live sign-in sessions, most recently active first: device, IP and whether somebody else opened it as them.',
    scope: 'users:read',
    danger: Danger::Read,
    schema: 'UserSession',
    tag: 'Users',
    rest: ['GET', '/users/{id}/sessions'],
)]
final readonly class ListUserSessions implements Action
{
    /** The most recent sessions answered; enough to recognise a device, not a log. */
    private const int LIMIT = 50;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));

        $sessions = Session::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_active_at')
            ->limit(self::LIMIT)
            ->get();

        $rows = [];

        foreach ($sessions as $session) {
            $rows[] = [
                'id' => $session->id,
                'user_agent' => $session->user_agent,
                'ip' => $session->ip,
                'last_active_at' => $session->last_active_at?->toIso8601String(),
                'expires_at' => $session->expires_at->toIso8601String(),
                // A session somebody else opened as this person — worth calling out.
                'impersonation' => in_array('impersonation', $session->amr, true),
            ];
        }

        return ActionResult::items($sessions, $rows);
    }
}
