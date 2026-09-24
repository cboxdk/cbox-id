<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\SignedInSession;

/**
 * Which session THIS browser holds, for the package's RP-initiated logout.
 *
 * `/oauth/logout` without a verifiable `id_token_hint` may only sign out this browser. The
 * package's default answers null here — it cannot know that {@see PlatformAuth} keeps the
 * session, not Laravel's guard — so that logout cleared the cookie and left the session
 * row active, and no application the session had signed the person in to was told
 * (OIDC Back-Channel Logout never fired). Answering with the session the auth middleware
 * resolved for this request lets the package end it properly.
 *
 * From {@see CurrentUser}, which is populated from the server-side session this browser
 * has already proven, never from a request parameter.
 *
 * WHILE IMPERSONATING it answers the impersonation session, deliberately — unlike
 * {@see PlatformSignedInSubject}, which answers nobody. That one's caller ends EVERY
 * session the subject holds, and an operator must not sign a customer out of their own
 * devices. This one's caller ends one session: the impersonation session itself, which is
 * the operator's to end.
 */
final class PlatformSignedInSession implements SignedInSession
{
    public function __construct(private readonly CurrentUser $me) {}

    public function id(): ?string
    {
        $id = $this->me->session()?->id;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
