<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Platform\Console\ConsoleOrganization;
use App\Platform\Console\ConsoleScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * WHICH TENANT THE ENVIRONMENT CONSOLE IS ACTING ON — chosen, cleared, and searched.
 *
 * A SEARCH, NOT A LIST. The control this replaces rendered a `<form>` per organization
 * into the chrome of every console page: fine for the seven an engineer has locally, and
 * an outage for the customer with four thousand — the document went from 59 KB to 3.5 MB
 * before anybody clicked anything. An unbounded set needs a chooser that stays bounded
 * whatever the set does, so this answers a page at a time and lets the administrator type
 * to narrow it.
 *
 * The search is the only JSON endpoint in the console, and it is one deliberately: the
 * alternative is a partial reload that writes the typed term into the address bar, and the
 * term is chrome state rather than where you are.
 */
final readonly class ActingOrganizationController
{
    public function __construct(private ConsoleScope $scope) {}

    public function search(Request $request): JsonResponse
    {
        $this->assertSignedIn();

        $results = $this->scope->searchOrganizations($request->string('q')->toString());

        return response()->json([
            'results' => array_map(static fn (ConsoleOrganization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
            ], $results),
            /*
             * Only asked once the page is full: "8 of 8" is noise, and a COUNT over every
             * organization in the environment is exactly the unbounded read this control
             * exists to stop paying.
             */
            'total' => count($results) < ConsoleScope::SWITCHER_LIMIT
                ? count($results)
                : $this->scope->organizationCount(),
        ]);
    }

    /**
     * Act on behalf of one organization from now on.
     *
     * Refused rather than ignored when it is not this administrator's to choose — a silent
     * no-op would leave somebody looking at one organization's name while acting on
     * another's data.
     */
    public function choose(Request $request): RedirectResponse
    {
        $this->assertSignedIn();

        try {
            $this->scope->chooseOrganization($request->string('organization')->toString());
        } catch (AuthorizationException $e) {
            return back()->withErrors(['organization' => $e->getMessage()]);
        }

        return $this->reload();
    }

    /**
     * Go back to acting on the whole environment.
     *
     * The missing half of choosing, and its absence made the control a one-way door: an
     * administrator who picked an organization had every list, count and form in the
     * console filtered to it for the rest of the session, with signing out as the only way
     * back to the environment-wide view they arrived on.
     */
    public function clear(): RedirectResponse
    {
        $this->assertSignedIn();

        try {
            $this->scope->clearOrganization();
        } catch (AuthorizationException $e) {
            return back()->withErrors(['organization' => $e->getMessage()]);
        }

        return $this->reload();
    }

    /**
     * BACK TO THE PAGE THEY WERE ON, wholly re-rendered.
     *
     * Every part of that page is scoped to the acting organization — the rail's counts, the
     * sub-nav, the page itself — so anything less than a full re-render would leave most of
     * the screen describing the organization they just left. `back()` needs no redirect
     * TARGET beyond the referer Laravel already validates as same-origin, so there is
     * nothing here to point off-site.
     */
    private function reload(): RedirectResponse
    {
        return back()->with('status', 'Now acting on '.($this->scope->organizationName() ?? 'the whole environment').'.');
    }

    /**
     * Reaching the console at all is the authorization here: the scope refuses a choice
     * that is not this administrator's to make on its own terms, and refuses the verb
     * outright on a plane that does not have one.
     */
    private function assertSignedIn(): void
    {
        abort_if(! $this->scope->signedIn(), 403);
    }
}
