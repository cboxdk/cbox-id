<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\RenameOrganizationRequest;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * IDENTITY PLATFORM › ACCOUNT SETTINGS.
 *
 * Management only, and DELETION IS DELIBERATELY NOT A BUTTON: deleting an account tears
 * down every project and environment it owns, which means live identity providers other
 * people's users are signing in through. It is a support request, and the page says so
 * rather than offering a control it would then have to talk somebody out of.
 */
final readonly class AccountSettingsController extends ConsoleController
{
    public function edit(Organizations $organizations): Response|RedirectResponse
    {
        $organization = $this->acting($organizations);

        if ($organization === null || $this->scope->capabilities()?->canManageMembers() !== true) {
            return to_route('projects');
        }

        return $this->page('console/account-settings', 'Account settings', [
            'name' => $organization->name,
        ]);
    }

    public function update(
        RenameOrganizationRequest $request,
        Organizations $organizations,
    ): RedirectResponse {
        $organization = $this->acting($organizations);

        abort_if($organization === null, 403);
        abort_unless($this->scope->capabilities()?->canManageMembers() === true, 403);

        /*
         * Renamed on the model rather than through a contract verb: `Organizations` has no
         * rename(). The account plane's writer did, and it is the one verb of that
         * interface with no counterpart here — worth naming so a future reader does not
         * assume it was overlooked.
         *
         * IN THE PLATFORM ROOT, because the WRITE is guarded too: `BelongsToEnvironment`
         * refuses a cross-environment save outright, so a rename issued from any other
         * host raises rather than silently writing nowhere.
         */
        app(PlatformRoot::class)->run(
            fn () => $organization->forceFill(['name' => $request->name()])->save(),
        );

        return back()->with('status', 'Account settings saved.');
    }

    /**
     * The organization being administered, or null when there is none to act on.
     *
     * IN THE PLATFORM ROOT: `organizations` is environment-owned, and read from whatever
     * host serves the console this finds nothing — so the guard above would bounce an
     * organization's own owner off their own settings page.
     */
    private function acting(Organizations $organizations): ?Organization
    {
        $id = $this->scope->organizationId();

        return $id === null ? null : app(PlatformRoot::class)->run(fn () => $organizations->find($id));
    }
}
