<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\SignedOutController;
use App\Platform\ConsoleSignIn\ConsoleSignIn;
use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
use App\Platform\SsoMandates;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class SessionController extends Controller
{
    public function destroy(Request $request, PlatformAuth $auth, Impersonation $impersonation): SymfonyResponse
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
        //    session, which every console there — environment consoles included — runs on
        //    ({@see PlatformAuth::logoutAll()}). It lands on "You're signed out".
        //  - On a tenant's host it is the END-USER context — the tenant's hosted sign-in and
        //    My account. Only those sessions end. It lands on the tenant's sign-in, as it
        //    always has.
        $root = $auth->onPlatformRoot();
        // LOGIN-HOST MODE: the console is a relying party. Its own sessions and the SSO
        // session they came from end here, on the server ({@see PlatformAuth::logoutAll()});
        // the browser then goes to the login host, which drops its SSO cookie and sends
        // it back to "You're signed out" (RP-initiated logout).
        $door = app(ConsoleSignIn::class);
        $away = $door->active($request) ? $door->signOutUrl() : null;

        $auth->logoutAll($request);

        if ($away !== null) {
            Inertia::clearHistory();

            return Inertia::location($away);
        }

        if ($root) {
            return SignedOutController::landing(fromConsole: false);
        }

        return redirect()->route('login')->with('status', __('console.you_have_been_signed_out_02b52a32'));
    }

    public function switchOrganization(Request $request, PlatformAuth $auth, Memberships $memberships, CurrentUser $me, SsoMandates $mandates): SymfonyResponse
    {
        $organizationId = $request->string('organization')->toString();

        // Only switch into an org the signed-in subject actually belongs to —
        // never trust the posted id on its own.
        if ($organizationId !== '' && $memberships->of($organizationId, $me->id()) !== null) {
            // …and, with SSO mandates per organization, only on a session that came through
            // its connection. Otherwise through the connection first, and back here in it.
            if (! $mandates->admits($me->session(), $organizationId)) {
                $mandate = $mandates->forOrganization($organizationId);

                if ($mandate->startUrl === null) {
                    return redirect()->route('dashboard')
                        ->with('error', __('auth.login.mandate.no_provider', ['organization' => $mandate->organizationName]));
                }

                $request->session()->put(IntendedUrl::KEY, route('dashboard'));

                // Login-host mode: through the login host, as the console's own client.
                $door = app(ConsoleSignIn::class);

                return Inertia::location($door->active($request) ? $door->startUrl(['enter' => $organizationId]) : $mandate->startUrl);
            }

            $auth->switchOrganization($request, $organizationId);
        }

        return redirect()->route('dashboard');
    }
}
