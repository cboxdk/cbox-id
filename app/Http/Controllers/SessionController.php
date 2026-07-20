<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

            return redirect()->route($marker->isAccountMember() ? 'environment.home' : 'operator.organizations');
        }

        $auth->logout($request);

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
