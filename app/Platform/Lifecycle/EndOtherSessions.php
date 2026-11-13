<?php

declare(strict_types=1);

namespace App\Platform\Lifecycle;

use App\Platform\OAuth\GrantWithdrawal;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ends every live session of a person but the one given, and the app grants those
 * sessions produced.
 *
 * One answer for the three doors that do this: "sign out every other session", a changed
 * password on the account page, and the password change a hold forces. Ending the session
 * rows alone told the apps by back-channel logout and left them their refresh tokens, so
 * an app somebody had authorized from a stolen session went on minting access tokens after
 * the owner signed it out. Its grants are withdrawn first ({@see GrantWithdrawal}), then
 * each session is revoked through the session manager, one at a time, so each one fans out
 * its back-channel logout.
 *
 * The session being used keeps its grants, and so does every grant that came from no
 * session at all (a device-flow sign-in such as the CLI's): neither was opened by the
 * sessions being ended.
 */
final readonly class EndOtherSessions
{
    public function __construct(private SessionManager $sessions, private GrantWithdrawal $grants) {}

    /** How many sessions were ended. */
    public function handle(string $subjectId, ?string $keepSessionId): int
    {
        $ids = [];

        foreach (Session::query()
            ->where('user_id', $subjectId)
            ->whereNull('revoked_at')
            ->when($keepSessionId !== null, fn (Builder $query): Builder => $query->whereKeyNot($keepSessionId))
            ->pluck('id') as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        $this->grants->fromSessions($subjectId, $ids);

        foreach ($ids as $id) {
            $this->sessions->revoke($id);
        }

        return count($ids);
    }
}
