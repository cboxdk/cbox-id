<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SessionController extends Controller
{
    public function destroy(Request $request, PlatformAuth $auth): RedirectResponse
    {
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
