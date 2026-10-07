<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Workspace\UpdateWorkspaceSettings;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\RenameOrganizationRequest;
use App\Platform\Help\HelpTopic;
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

        return $this->page('console/account-settings', 'Workspace settings', [
            'help' => HelpProps::for(HelpTopic::WorkspaceSettings),
            'name' => $organization->name,
        ]);
    }

    public function update(RenameOrganizationRequest $request, Organizations $organizations): RedirectResponse
    {
        $organization = $this->acting($organizations);

        abort_if($organization === null, 403);
        abort_unless($this->scope->capabilities()?->canManageMembers() === true, 403);

        // Unchanged is not an act: no write, no line on the log, no "saved".
        if ($request->name() === $organization->name) {
            return back();
        }

        /*
         * Through the action `PATCH /api/v1/workspace` runs ({@see UpdateWorkspaceSettings}):
         * the rename in the platform root, and the line on the workspace's own log under
         * `organization.renamed` — the name the environment console's Settings page writes
         * too. This page once wrote nothing, so the name on every invoice and in every
         * invitation email could change with no record of who changed it.
         */
        $result = $this->act(UpdateWorkspaceSettings::class, ['name' => $request->name()], ['name' => 'name'], 'name');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Workspace settings saved.');
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
