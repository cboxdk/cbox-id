<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
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
    public function index(PlatformAuth $auth): Response
    {
        return $this->page('auth/accounts', 'Choose account', [
            'accounts' => $auth->accounts(),
        ]);
    }

    public function switchTo(Request $request, PlatformAuth $auth): RedirectResponse
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
        return redirect()->to(IntendedUrl::pullForSubject() ?? route('dashboard'));
    }
}
