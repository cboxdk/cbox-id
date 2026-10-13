<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\SignedOutController;
use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use App\Platform\PlatformAuth;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SessionController extends Controller
{
    public function destroy(Request $request, PlatformAuth $auth, Impersonation $impersonation): RedirectResponse
    {
        // While impersonating, the browser IS the subject, so this subject-plane
        // logout is reachable — but running the normal multi-account logout could
        // revoke the impersonated session and activate ANOTHER held account while the
        // impersonation marker lingers (audit/banner desync). "Sign out" here must
        // EXIT impersonation: restore the acting operator/env-admin and return to it.
        $marker = $impersonation->active();
        if ($marker !== null) {
            $impersonation->exit($request);

            return redirect()->route($marker->isMembership() ? 'environment.home' : 'platform.organizations');
        }

        // SIGN OUT OF THE CONTEXT THIS IS. Every account the browser holds here — it used to
        // end only the active one.
        //
        //  - On the account host (`cboxid.com`) that is the ADMIN context: the root's
        //    session, and every environment console handed off from it on any tenant's
        //    host ({@see PlatformAuth::logoutAll()}). It lands on "You're signed out".
        //  - On a tenant's host it is the END-USER context — the tenant's hosted sign-in and
        //    My account. Only those sessions end; the environment console's administrator
        //    on the same host is another context and stays signed in (the console's own
        //    Sign out is `admin.logout`). It lands on the tenant's sign-in, as it always has.
        $root = $auth->onPlatformRoot();

        $auth->logoutAll($request);

        if ($root) {
            return SignedOutController::landing(fromConsole: false);
        }

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    public function switchOrganization(Request $request, PlatformAuth $auth, Memberships $memberships, CurrentUser $me): RedirectResponse
    {
        $organizationId = $request->string('organization')->toString();

        // Only switch into an org the signed-in subject actually belongs to —
        // never trust the posted id on its own.
        if ($organizationId !== '' && $memberships->of($organizationId, $me->id()) !== null) {
            $auth->switchOrganization($request, $organizationId);
        }

        return redirect()->route('dashboard');
    }
}
