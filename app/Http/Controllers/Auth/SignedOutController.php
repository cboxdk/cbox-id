<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * "YOU'RE SIGNED OUT" — where every sign-out lands, on every host.
 *
 * It used to be a sign-in door. The account console sent people to `/login`, which is
 * harmless; an environment's admin console sent them to `admin.login`, which is its GATE —
 * and the gate's job is to get a signed-out administrator back in, so it bounced them to
 * the account host's open-environment door, which minted a handoff and put them straight
 * back in the console they had just left. A page that does nothing is the only landing
 * that cannot re-enter anything by itself. The Sign in button is there for when they mean
 * it.
 *
 * Rendered for anybody, signed in or not, and never redirects: a page that sent a
 * signed-in visitor on to a console would be the same bug one hop later.
 */
final readonly class SignedOutController extends PageController
{
    /** Signed out of an environment's admin console: "Sign in" goes back through its gate. */
    public const FROM_CONSOLE = 'console';

    /**
     * End the request that signed somebody out: here, on this same host, with the
     * browser's page history cleared, so Back cannot redraw a console page the server
     * would now refuse.
     *
     * @param  bool  $fromConsole  whether what ended was an environment admin console session
     */
    public static function landing(bool $fromConsole): RedirectResponse
    {
        Inertia::clearHistory();

        return redirect()->route('signed-out', $fromConsole ? ['from' => self::FROM_CONSOLE] : [])
            ->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function show(Request $request): Response
    {
        // An environment's console signs in through its gate, which sends a signed-out
        // administrator to the account host's sign-in and back; everybody else signs in on
        // this host's own page. Only ever one of two routes on this host — the parameter
        // picks between them and can name nothing else.
        $signIn = $request->query('from') === self::FROM_CONSOLE
            ? route('environment.home')
            : route('login');

        $response = $this->page('auth/signed-out', __('auth.signed_out.title'), [
            'signInUrl' => $signIn,
        ])->toResponse($request);

        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
