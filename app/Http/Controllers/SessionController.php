<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\PlatformAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SessionController extends Controller
{
    public function destroy(Request $request, PlatformAuth $auth): RedirectResponse
    {
        $auth->logout($request);

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    public function switchOrganization(Request $request, PlatformAuth $auth): RedirectResponse
    {
        $organizationId = $request->string('organization')->toString();

        $auth->switchOrganization($request, $organizationId);

        return redirect()->route('dashboard');
    }
}
