<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Platform\Actions\ActionRefused;
use App\Platform\ServiceAccess\ProductAdmission;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * THE ORGANIZATION'S HOME, `/orgs/{org}/overview` — where a person lands in their Cbox
 * organization.
 *
 * Most organizations only run their own team in Cbox: who is in it, how they sign in and
 * what it costs. So that comes first. Building sign-in for one's own product (an identity
 * platform) is one card on it, offered to whoever may create one, and the list of platforms
 * appears beside it only once the organization has one.
 *
 * Every link is offered only to somebody whose role opens it, asked of the same capabilities
 * the target page asks.
 */
final readonly class OrganizationHomeController extends ConsoleController
{
    public function __invoke(Memberships $members, Organizations $organizations, OrganizationProjects $projects): Response|RedirectResponse
    {
        $organizationId = $this->scope->organizationId();
        $can = $this->scope->capabilities();

        if ($organizationId === null || $this->scope->membershipRole() === null || $can === null) {
            return to_route('orgs.index');
        }

        $organization = app(PlatformRoot::class)->run(fn () => $organizations->find($organizationId));
        $people = $can->canReadMembers()
            ? app(PlatformRoot::class)->run(fn (): int => $members->countForOrganization($organizationId))
            : null;
        $platforms = $can->canReadEnvironments() ? $projects->forOrganization($organizationId)->count() : 0;
        $billing = config('billing.enabled') === true && $can->canReadBilling();

        return $this->page('console/orgs/home', __('console.overview_d4b1ea57'), [
            'name' => $organization->name ?? '',
            'people' => $people,
            'peopleHref' => $can->canReadMembers() ? route('orgs.settings.members') : null,
            'billingHref' => $billing ? route('orgs.settings.billing', ['organization' => $organizationId]) : null,
            // The rail's own gate for these areas, so the home never offers what the rail hides.
            'signInHref' => Console::context()->isAdmin() ? route('connections') : null,
            'auditHref' => Console::context()->isAdmin() ? route('audit') : null,
            'settingsHref' => $can->isOwner() ? route('orgs.settings') : null,
            'platforms' => $platforms,
            'platformsHref' => $platforms > 0 ? route('orgs.projects') : null,
            // Creating one is the ID product, behind admission like the list.
            'createPlatformHref' => $can->canManageEnvironments() && $this->admitted($organizationId)
                ? route('orgs.projects.create')
                : null,
        ]);
    }

    private function admitted(string $organizationId): bool
    {
        try {
            app(ProductAdmission::class)->assertGranted($this->scope->actorId(), $organizationId);

            return true;
        } catch (ActionRefused) {
            return false;
        }
    }
}
