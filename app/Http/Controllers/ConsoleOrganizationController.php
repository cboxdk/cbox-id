<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Console\ConsoleScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Choose which organization the environment console is acting on.
 *
 * The environment plane administers many organizations; the organization plane
 * administers exactly one. That is the ONLY difference between the two, and modelling it
 * as a picker — the same shape as the environment switcher already beside it — is what
 * lets one component serve both planes instead of two components drifting apart.
 *
 * The choice is refused, not ignored, when it is not the caller's to make: {@see
 * ConsoleScope::chooseOrganization()} throws on the organization plane and on an
 * organization outside this environment. A silent no-op here would leave an administrator
 * looking at one organization's name while acting on another's data.
 */
final class ConsoleOrganizationController extends Controller
{
    public function __invoke(Request $request, ConsoleScope $scope): RedirectResponse
    {
        try {
            $scope->chooseOrganization($request->string('organization')->toString());
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Back to where they were. The console's pages are all scoped to the acting
        // organization, so the page they are on is still the page they want — now about
        // somebody else.
        return back();
    }
}
