<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Platform\IntendedUrl;
use App\Platform\OAuth\AppSignIns;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /continue` — ON TO WHERE THIS BROWSER WAS GOING.
 *
 * A person on their way to an app can be held on one of their own pages (a second factor
 * to enrol) or finish a mailed link in a browser that never held the app's request. Both
 * need one address that means "carry on":
 *
 *  - the authorization waiting in this session, followed and spent like every sign-in
 *    door spends it, so a later sign-in is not sent to a request that was answered;
 *  - else the home of the app a mailed link named ({@see AppSignIns::continueTo()}), where
 *    the app starts a new request and the session that now exists answers it;
 *  - else the console.
 *
 * It takes nothing from its own address: where it leads was decided by `/oauth/authorize`
 * or noted in the session by the door that sent the person here.
 */
final readonly class ContinueController extends PageController
{
    public function __invoke(Request $request, AppSignIns $apps): Response
    {
        $intended = IntendedUrl::pullForSubject();

        // Never to itself: the sign-in door that pulled this address as an intent is gone
        // by now, and one left behind would be a loop.
        if ($intended !== null && parse_url($intended, PHP_URL_PATH) !== '/'.$request->path()) {
            return redirect()->to($intended);
        }

        $home = $apps->continuation($request);

        // A full visit when an Inertia request brought the person here: the app is
        // another origin, which an XHR cannot follow a redirect to.
        return $home !== null ? $this->inertia->location($home) : redirect()->route('dashboard');
    }
}
