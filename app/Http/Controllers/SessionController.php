<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\SetEnvironment;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
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

    /**
     * Point the console at another environment — the hard outer boundary. Only a
     * slug that resolves to a real environment is honoured; everything below is
     * environment-owned and deny-by-default, so an operator without a presence in
     * the target sees empty state rather than another plane's data.
     */
    public function switchEnvironment(Request $request, CurrentUser $me, EnvironmentContext $context): RedirectResponse
    {
        $id = $request->string('environment')->toString();

        $environment = $id !== '' ? Environment::query()->find($id) : null;

        if ($environment === null) {
            return redirect()->route('dashboard');
        }

        // Deny-by-default is the boundary. Only switch into a plane where the
        // operator actually has an identity — otherwise the auth guard can't load
        // them there on the next request and they'd be silently signed out.
        // Explain instead of orphaning the session.
        $email = $me->email();
        $hasIdentity = $email !== null && $context->runAs(
            $environment,
            fn (): bool => User::query()->where('email', $email)->exists(),
        );

        if (! $hasIdentity) {
            return back()->with('status', 'You do not have an account in the '.$environment->name.' environment yet.');
        }

        $request->session()->put(SetEnvironment::SESSION_KEY, $environment->slug);

        return redirect()->route('dashboard');
    }
}
