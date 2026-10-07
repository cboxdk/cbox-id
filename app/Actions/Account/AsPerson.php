<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Actions\Principal\PersonPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who the account actions act FOR: the person themself, whichever door they came through.
 * A helper, not an action.
 *
 * Every account action is keyed to {@see self::subjectId()} and takes no id of an account
 * from its caller — a session id, a passkey id or a key id is looked up WITH the person in
 * the query, so somebody else's is not found. That is why the account plane needs no role
 * check anywhere: there is nobody else's account to reach.
 *
 * In the console the person is the signed-in subject ({@see CurrentUser}); through a token
 * they delegated, it is the {@see PersonPrincipal} the token resolved to. No management key
 * is either, and every account action refuses one.
 */
final class AsPerson
{
    /**
     * @throws AuthorizationException
     */
    public static function subjectId(Principal $principal): string
    {
        $id = match (true) {
            $principal instanceof PersonPrincipal => $principal->subjectId(),
            $principal instanceof ConsoleSessionPrincipal => app(CurrentUser::class)->check() ? app(CurrentUser::class)->id() : null,
            default => null,
        };

        if (! is_string($id) || $id === '') {
            throw new AuthorizationException('Only the person themself can change their account.');
        }

        return $id;
    }

    /**
     * The sign-in session the person is using, which "everywhere else" spares. A delegated
     * token that is no session has none, and everywhere else is everywhere.
     */
    public static function currentSessionId(Principal $principal): ?string
    {
        if ($principal instanceof PersonPrincipal) {
            return $principal->currentSessionId();
        }

        $current = session()->get(PlatformAuth::SESSION_KEY);

        return is_string($current) && $current !== '' ? $current : null;
    }

    /**
     * Who the trail names: the person, as a user of this environment — the way their own
     * account page has always recorded what they did to it.
     *
     * @throws AuthorizationException
     */
    public static function actor(Principal $principal): AuditActor
    {
        return AuditActor::user(self::subjectId($principal));
    }
}
