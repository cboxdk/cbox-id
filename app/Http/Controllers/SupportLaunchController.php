<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\SupportAccess\SupportHandoff;
use Cbox\Id\OAuthServer\Contracts\SupportSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

/**
 * THE SUPPORT SESSION'S STEP ONTO THE ENVIRONMENT'S OWN HOST.
 *
 * "Sign in to <app> as <user>" is started in the environment console, which lives on the
 * app host. The app's own sign-in, though, arrives at the environment's authorization
 * endpoint on the environment's host, and that endpoint answers it with the support
 * session's code only for the browser the session was started in. The two hosts share no
 * cookie, so the console sends the browser here first, through a short-lived signed link
 * naming the session, and this puts the session's hand-off into THIS host's session before
 * sending the browser on to the app.
 *
 * The link is the credential, so it is spent on first use, lives two minutes, names one
 * session that must still be active, and the page that follows it leaks no referrer.
 */
final class SupportLaunchController extends Controller
{
    /** How long the launch link works, in seconds. */
    public const TTL = 120;

    public function __invoke(string $session, SupportSessions $sessions, SupportHandoff $handoff): RedirectResponse
    {
        $active = $sessions->active($session);

        abort_if($active === null, 404);

        // Spent on first use: a second visit, from anywhere, finds nothing.
        abort_unless(Cache::add('cbox.support_launch.'.$active->id, true, self::TTL), 404);

        $console = request()->query('console');

        $handoff->put((string) $active->client_id, (string) $active->id, (string) $active->actor_id, is_string($console) && $console !== '' ? $console : null);

        $launch = request()->query('to');
        abort_unless(is_string($launch) && $launch !== '', 404);

        return redirect()->away($launch)->withHeaders([
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ]);
    }
}
