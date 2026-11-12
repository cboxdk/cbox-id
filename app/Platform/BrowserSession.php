<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\Authenticate;
use App\Platform\Surfaces\SessionSurface;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\Session;
use Illuminate\Http\Request;

/**
 * The platform session THIS browser holds, on a route the authentication middleware does
 * not run on.
 *
 * {@see CurrentUser} is filled in only by {@see Authenticate}, and the
 * framework's `/oauth/logout` does not run it. Asked there, CurrentUser said "nobody", so an
 * app's sign-out dropped the cookie and left the session row active: no app the session
 * had signed the person in to was told (Back-Channel Logout), and a verified hint could not
 * end the person's other devices. This reads the same proof that middleware reads: the
 * session id kept in this browser's server-side session, accepted only on the surface that
 * minted it and only while the session row is active. Never a request parameter.
 */
final readonly class BrowserSession
{
    public function __construct(
        private CurrentUser $me,
        private SessionManager $sessions,
        private SessionSurface $surface,
    ) {}

    public function current(): ?Session
    {
        $resolved = $this->me->session();

        if ($resolved !== null) {
            return $resolved;
        }

        $request = app(Request::class);

        if (! $request->hasSession() || ! $this->surface->admits($request)) {
            return null;
        }

        $id = $request->session()->get(PlatformAuth::SESSION_KEY);

        return is_string($id) && $id !== '' ? $this->sessions->active($id) : null;
    }
}
