<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Middleware\EnterConsoleThroughStart;
use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
use App\Platform\Surfaces\ReturnUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * THE ACCOUNT CHOOSER — which of the identities signed in on this browser you are acting
 * as.
 *
 * Reached from the console, and as the target of an OAuth `prompt=select_account`. The
 * point of it is that switching costs no re-authentication: each account already proved
 * itself when it was added, and the browser is holding all of them.
 */
final readonly class AccountsController extends PageController
{
    public function index(Request $request, PlatformAuth $auth, ReturnUrl $returns): Response
    {
        // Where the console or an app sent the person from (`?return_to=`), kept for the
        // switch that follows.
        $returns->remember($request);

        return $this->page('auth/accounts', __('auth.accounts.title'), [
            'accounts' => $auth->accounts(),
        ]);
    }

    public function switchTo(Request $request, PlatformAuth $auth, ReturnUrl $returns): RedirectResponse
    {
        $subjectId = (string) $request->string('subject');

        if (! $auth->switchTo($request, $subjectId)) {
            // The browser is not holding that identity — or is no longer. Refused
            // silently rather than explained: this is a list the page just rendered, so a
            // miss means the session moved underneath it, and the honest answer is to
            // show the list again.
            return back();
        }

        /*
         * Resume wherever the flow was headed — an OAuth authorize request, say —
         * PROVIDED it is somewhere a subject can go. The admin console's refusals write
         * the same key on a tenant host, and following one of those would land a subject
         * on a door their session cannot open.
         */
        // Else back to where the chooser was opened from, else the console. In login-host
        // mode a console address is entered through the console's own start, so the
        // console takes up the account that is active now ({@see EnterConsoleThroughStart}).
        return redirect()->to(IntendedUrl::pullForSubject() ?? $returns->pull($request) ?? route('dashboard'));
    }
}
