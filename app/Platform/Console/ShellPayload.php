<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Props\Shell\ActingOrganizationProps;
use App\Http\Props\Shell\NavAreaProps;
use App\Http\Props\Shell\NavPageProps;
use App\Http\Props\Shell\ShellProps;
use App\Http\Props\Shell\SwitchOptionProps;
use App\Platform\CurrentUser;
use App\Platform\Entitlements;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Navigation\ConsoleNav;
use App\Platform\Navigation\ConsoleNavigation;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * WHAT THE CONSOLE CHROME IS, FOR THIS REQUEST.
 *
 * The two blade layouts this replaces each computed the same answers from the same
 * sources in their own idiom, and the drift between them is written down all over both
 * files: one shell carried the impersonation banner and the other did not, so an operator
 * who started an impersonation from a page on the wrong layout had no way out; one used
 * the shared mobile navigation and the other hand-rolled a drawer, so the same page
 * behaved differently on a phone depending on which plane served it.
 *
 * None of that was a rendering problem. It was that the chrome was described twice.
 */
final readonly class ShellPayload
{
    /**
     * Areas an ordinary member sees.
     *
     * Everything else in the rail is organization ADMINISTRATION. Two of these are exempt
     * from the admin gate for opposite reasons, and both exemptions are load-bearing:
     *
     *  - `identity-platform`, because the membership that places an account member in
     *    their own account's organization carries MembershipRole::Member on purpose. An
     *    account OWNER is therefore not an org admin there, and this gate would hide
     *    their own projects and billing from them. That area gates every one of its pages
     *    on the membership role already, which is the authority for those capabilities.
     *
     *  - the three `platform` areas, because this gate asks whether you administer the
     *    organization you are currently ACTING FOR, and an operator's authority has
     *    nothing to do with that. The person who runs the install is routinely a plain
     *    member of the customer they are looking at, so leaving them to this gate hides
     *    the platform section from exactly the people it exists for. Their own
     *    `platform.operator` feature is the stronger gate and has already run: an area
     *    survives to here only if its pages did.
     */
    private const MEMBER_AREAS = [
        'overview',
        'account',
        'identity-platform',
        'platform',
        'platform-insights',
        'platform-admin',
    ];

    /** Areas whose pages are about the whole install rather than one customer on it. */
    private const PLATFORM_AREAS = ['platform', 'platform-insights', 'platform-admin'];

    /**
     * The soft entitlement lock, by route. The page is shown and marked rather than
     * hidden: an organization that cannot discover a capability exists cannot buy it.
     */
    private const ENTITLEMENT_FEATURE = [
        'connections' => 'sso',
        'directories' => 'scim',
        'provisioning' => 'scim',
    ];

    public function __construct(
        private ConsoleScope $scope,
        private CurrentUser $user,
        private Entitlements $entitlements,
        private ConsoleNavigation $navigation,
        private Request $request,
    ) {}

    /**
     * Null when this request has no console chrome around it — a sign-in page, the admin
     * portal, an error. The React shell asks one question rather than five.
     */
    public function build(): ?ShellProps
    {
        return match ($this->scope->plane()) {
            ConsolePlane::Environment => $this->environmentShell(),
            ConsolePlane::Organization => $this->organizationShell(),
        };
    }

    /**
     * THE ORGANIZATION PLANE — the console a subject of this environment signs in to.
     *
     * Its navigation comes from the console-kit registry rather than from a list here, so
     * a module that `composer require`s in appears in the rail with no edit to this file.
     */
    private function organizationShell(): ?ShellProps
    {
        if (! $this->user->check()) {
            return null;
        }

        $isAdmin = Console::context()->isAdmin();

        $areas = [];

        foreach (Console::nav()->areas() as $area) {
            if (! $isAdmin && ! in_array($area->key, self::MEMBER_AREAS, true)) {
                continue;
            }

            $pages = [];

            foreach ($area->pages() as $page) {
                // The HARD gate: an inactive feature has no page and no route, so a
                // disabled module cannot be reached by typing the URL either.
                if ($page->feature !== null && ! Console::featureActive($page->feature)) {
                    continue;
                }

                $feature = self::ENTITLEMENT_FEATURE[$page->route] ?? null;

                $pages[] = new NavPageProps(
                    route: $page->route,
                    href: route($page->route),
                    label: $page->label,
                    active: $this->routeIsCurrent($page->route),
                    badge: $feature !== null && ! $this->entitlements->entitledOrgFeature($feature)
                        ? 'Enterprise'
                        : null,
                );
            }

            // An area with nothing left in it is not an area. This is what makes a
            // capability absent rather than gated on a plane that does not serve it.
            if ($pages === []) {
                continue;
            }

            $areas[] = new NavAreaProps(
                key: $area->key,
                label: $area->label,
                // A plugin may register an area without one, and the rail is icons —
                // rendering the blank is worse than rendering the wrong thing, because a
                // blank square in the primary navigation reads as a broken build.
                icon: $area->icon ?? 'layers',
                href: $pages[0]->href,
                // Filled in below, once it is known which area owns the page.
                active: false,
                current: false,
                pages: $pages,
            );
        }

        $areas = $this->markActive($areas);
        $active = $this->activeArea($areas);

        return new ShellProps(
            areas: $areas,
            activeArea: $active?->key,
            section: $active !== null && in_array($active->key, self::PLATFORM_AREAS, true)
                ? 'Platform'
                : null,
            organizations: $this->switchableOrganizations(),
            // The account plane's switcher above already names the one organization a
            // member acts in, and choosing another is the authorization this plane exists
            // to withhold.
            actingOrganization: null,
            environments: $this->targetEnvironments(),
            isOperator: $this->scope->isPlatformOperator(),
            brandHref: route('dashboard'),
            navPinned: $this->request->cookie('cbox-nav-pinned') === '1',
        );
    }

    /**
     * THE ENVIRONMENT PLANE — an account member administering one environment.
     *
     * Its navigation is declared in {@see ConsoleNavigation::environment()} rather than
     * in the console-kit registry, because these pages are not an organization's: they
     * are the control plane's view of every organization in the environment.
     */
    private function environmentShell(): ?ShellProps
    {
        if (app(EnvironmentAdminAuth::class)->membership() === null) {
            return null;
        }

        $nav = $this->navigation->environment();
        $areas = $this->markActive($this->fromConsoleNav($nav));
        $active = $this->activeArea($areas);

        return new ShellProps(
            areas: $areas,
            activeArea: $active?->key,
            // The environment console is already one environment's, and its name is in
            // the topbar. A second word in the tab title would say nothing.
            section: null,
            organizations: [],
            /*
             * THE CONTROL THAT DECIDES WHAT EVERY PAGE HERE MEANS.
             *
             * Every read in this console is written as
             * `when($id !== null, fn ($q) => $q->where('organization_id', $id))`, so this
             * selection is the difference between "the whole environment" and "one tenant".
             * Without the control an administrator who had chosen one could never get back
             * — signing out was the only way — which is exactly the one-way door
             * {@see ConsoleScope::clearOrganization()} was written to close.
             */
            actingOrganization: new ActingOrganizationProps(
                id: $this->scope->organizationId(),
                name: $this->scope->organizationName(),
                searchUrl: route('environment.acting-organization.search'),
                chooseUrl: route('environment.acting-organization.choose'),
                clearUrl: route('environment.acting-organization.clear'),
            ),
            environments: [],
            isOperator: false,
            brandHref: $areas === [] ? route('environment.home') : $areas[0]->href,
            navPinned: $this->request->cookie('cbox-nav-pinned') === '1',
        );
    }

    /**
     * @return list<NavAreaProps>
     */
    private function fromConsoleNav(ConsoleNav $nav): array
    {
        return array_map(
            fn ($area): NavAreaProps => new NavAreaProps(
                key: $area->label,
                label: $area->label,
                icon: $area->icon,
                href: $area->href(),
                active: false,
                current: false,
                pages: array_map(
                    fn ($page): NavPageProps => new NavPageProps(
                        route: $page->route,
                        href: $page->href(),
                        label: $page->label,
                        active: $page->isCurrent(),
                    ),
                    $area->pages,
                ),
            ),
            $nav->areas,
        );
    }

    /**
     * Decide which area owns this page, and say so once.
     *
     * `current` is set only for a single-page area. When there is a second tier, the
     * sub-nav entry is the current page — and two elements claiming `aria-current="page"`
     * is worse than none, because a screen reader announces both.
     *
     * @param  list<NavAreaProps>  $areas
     * @return list<NavAreaProps>
     */
    private function markActive(array $areas): array
    {
        $activeKey = null;

        foreach ($areas as $area) {
            foreach ($area->pages as $page) {
                if ($page->active) {
                    $activeKey = $area->key;

                    break 2;
                }
            }
        }

        // Nothing matched — a page outside the navigation entirely (the guided first run,
        // a detail route nobody listed). The rail falls back to the first area rather
        // than rendering with nothing selected, which reads as a broken shell.
        $activeKey ??= $areas[0]->key ?? null;

        return array_map(
            fn (NavAreaProps $area): NavAreaProps => new NavAreaProps(
                key: $area->key,
                label: $area->label,
                icon: $area->icon,
                href: $area->href,
                active: $area->key === $activeKey,
                current: $area->key === $activeKey && count($area->pages) === 1,
                pages: $area->pages,
            ),
            $areas,
        );
    }

    /**
     * @param  list<NavAreaProps>  $areas
     */
    private function activeArea(array $areas): ?NavAreaProps
    {
        foreach ($areas as $area) {
            if ($area->active) {
                return $area;
            }
        }

        return null;
    }

    /**
     * A page stays lit on its own detail and create routes (`users` → `users.show`) but
     * NOT on a sibling that merely shares a prefix: `audit` must not light up on
     * `audit-streams`. Hence two explicit patterns rather than one prefix test.
     */
    private function routeIsCurrent(string $route): bool
    {
        return $this->request->routeIs($route) || $this->request->routeIs($route.'.*');
    }

    /**
     * The organizations this subject belongs to — and an empty list when there is only
     * one, because a switcher that cannot switch is a control that lies.
     *
     * This runs on EVERY authenticated console page, so the single-membership case (the
     * overwhelming majority) costs zero organization queries, and the multi case resolves
     * every name in one batch rather than one query per membership.
     *
     * @return list<SwitchOptionProps>
     */
    private function switchableOrganizations(): array
    {
        $memberships = app(Memberships::class)->forUser($this->user->id());

        if ($memberships->count() <= 1) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = [];

        foreach ($memberships as $membership) {
            $ids[] = $membership->organization_id;
        }

        $organizations = app(Organizations::class)->findMany($ids);
        $currentId = $this->user->organizationId();

        $options = [];

        foreach ($memberships as $membership) {
            $id = $membership->organization_id;
            $organization = $organizations[$id] ?? null;

            // A membership whose organization no longer resolves is not a row somebody
            // can switch to. Skipped rather than rendered blank, which is what an
            // `?? 'Unknown'` here would produce.
            if ($organization === null) {
                continue;
            }

            $options[] = new SwitchOptionProps(
                id: $id,
                label: $organization->name,
                caption: $membership->role->label(),
                current: $id === $currentId,
            );
        }

        return $options;
    }

    /**
     * The environments an operator can point this console at.
     *
     * WHY THE OWNER IS NAMED. "Production" is a name half the customers on an install
     * will have, and a control that says only that tells an operator nothing about whose
     * estate their next click lands in. The owner is reached THROUGH the project;
     * `environments.account_id` was the shortcut, and it is gone.
     *
     * Two queries for the whole list even though this renders on every console page: the
     * work is skipped outright for the overwhelming majority of sessions (nobody is an
     * operator), and the owner names come from one batched lineage lookup rather than a
     * query per environment.
     *
     * @return list<SwitchOptionProps>
     */
    private function targetEnvironments(): array
    {
        if (! $this->scope->isPlatformOperator()) {
            return [];
        }

        $context = app(EnvironmentContext::class);
        $activeId = $context->current()?->environmentKey();

        /** @var Collection<int, Environment> $environments */
        $environments = $context->withoutScope(
            fn () => Environment::query()
                ->orderBy('created_at')
                ->get(['id', 'name', 'slug', 'project_id']),
        );

        if ($environments->count() <= 1) {
            return [];
        }

        $lineage = app(EnvironmentLineages::class)->for($environments);

        $options = [];

        foreach ($environments as $environment) {
            $options[] = new SwitchOptionProps(
                id: $environment->id,
                label: isset($lineage[$environment->id])
                    ? $lineage[$environment->id]->qualify($environment->name)
                    : $environment->name,
                caption: $environment->slug,
                current: $environment->id === $activeId,
                // Two DIFFERENT operations, and the Volt menu offered only one. Selecting
                // a row re-points this console at that environment while the operator
                // stays on the operator host with operator authority — which is what the
                // platform pages need, since they read through the pointed environment.
                // Opening it is the other thing the label looks like it means, and that
                // path (a signed handoff, no second login) was reachable only from
                // Projects. Both are here, and each says which it is.
                openHref: route('environment.open', $environment->id),
            );
        }

        return $options;
    }
}
