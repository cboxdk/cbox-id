<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\SignedOutController;
use Cbox\Id\Api\Http\Controllers\EndSessionController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET|POST /oauth/logout`, ending on the hosted signed-out page.
 *
 * The framework's endpoint ({@see EndSessionController}) signs the browser out and sends
 * it to the app's `post_logout_redirect_uri` when that address is registered. When there
 * is none, or it is not accepted, it answers with one line of plain text: correct for a
 * protocol endpoint, and a blank white page for the person who pressed "Sign out" in an
 * app. Every app signs out through here, so that person lands where every other sign-out
 * on this host lands ({@see SignedOutController}).
 *
 * Bound over the framework's controller in the container; the sign-out itself, the
 * redirect allow-list and the logging of a dropped redirect are the framework's and
 * untouched. Under `app.debug` the framework explains why a redirect was dropped, in the
 * body: that answer is left as it is, because the page would hide it from the developer
 * it is written for.
 */
final class HostedEndSessionController extends EndSessionController
{
    private const string SIGNED_OUT = 'You are signed out.';

    public function __invoke(Request $request): RedirectResponse|Response
    {
        $response = parent::__invoke($request);

        if ($response instanceof RedirectResponse || $response->getContent() !== self::SIGNED_OUT) {
            return $response;
        }

        // 303: the POST binding must not be replayed at the page.
        return redirect()->route('signed-out', status: 303)
            ->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
