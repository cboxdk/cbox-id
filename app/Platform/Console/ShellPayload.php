<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Props\Shell\NavAreaProps;
use App\Http\Props\Shell\NavPageProps;
use App\Http\Props\Shell\ShellNoticeProps;
use App\Http\Props\Shell\ShellProps;
use App\Platform\Agents\ActionApprovalInbox;
use App\Platform\CurrentUser;
use App\Platform\Entitlements;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Navigation\ConsoleNav;
use App\Platform\Navigation\ConsoleNavigation;
use App\Platform\OrganizationCapabilities;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
 *
 * BUILT ONCE PER REQUEST. Two callers ask for it on every console page — the controller,
 * for the tab title's section word, and the shared `shell` prop — and each used to build
 * the whole thing: the rail, the memberships, and now the projects and environments of the
 * context switcher. The result is kept on the REQUEST rather than on this object, so it
 * cannot outlive the request it describes however the container holds this class.
 */
final readonly class ShellPayload
{
    /** Where the built shell is kept, on the request it was built for. */
    public const MEMO = 'cbox.console.shell';

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
        'domains' => 'sso',
        'directories' => 'scim',
        'provisioning' => 'scim',
    ];

    public function __construct(
        private ConsoleScope $scope,
        private CurrentUser $user,
        private Entitlements $entitlements,
        private ConsoleNavigation $navigation,
        private Request $request,
        private ShellContext $context,
        private ActionApprovalInbox $approvals,
    ) {}

    /**
     * Null when this request has no console chrome around it — a sign-in page, the admin
     * portal, an error. The React shell asks one question rather than five.
     */
    public function build(): ?ShellProps
    {
        // An array around the answer, because NULL is an answer too — a page with no
        // chrome would otherwise be rebuilt by every caller that asked.
        $memo = $this->request->attributes->get(self::MEMO);

        if (is_array($memo) && array_key_exists('shell', $memo)) {
            return $memo['shell'] instanceof ShellProps ? $memo['shell'] : null;
        }

        $shell = match ($this->scope->plane()) {
            ConsolePlane::Environment => $this->environmentShell(),
            ConsolePlane::Organization => $this->organizationShell(),
        };

        $this->request->attributes->set(self::MEMO, ['shell' => $shell]);

        return $shell;
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
        $isOperator = $this->scope->isPlatformOperator();
        $workspace = $this->scope->atWorkspaceAltitude();
        $customer = $this->scope->atCustomerAltitude();

        // PLATFORM ADMIN IS A MODE, NOT THREE MORE AREAS. The platform section used to be
        // appended to the bottom of every rail an operator saw, so the controls that
        // suspend a customer sat one icon below the operator's own Settings, with nothing
        // to say which side of that line a page was on. Now the rail is one or the other:
        // the platform areas inside /platform, everything else outside it, and the account
        // menu is the door between them.
        $platformMode = $isOperator && $this->request->routeIs('platform.*');

        $areas = [];
        // Whether this page is one the workspace console does not offer — reached by URL.
        $offRail = false;

        // Every page the registry names, so a page does not light up on a route another
        // page owns more specifically (see routeIsCurrent()).
        $claimed = [];

        foreach (Console::nav()->areas() as $area) {
            foreach ($area->pages() as $page) {
                $claimed[] = $page->route;
            }
        }

        foreach (Console::nav()->areas() as $area) {
            if (! $isAdmin && ! in_array($area->key, self::MEMBER_AREAS, true)) {
                continue;
            }

            if (in_array($area->key, self::PLATFORM_AREAS, true) !== $platformMode) {
                continue;
            }

            $pages = [];

            foreach ($area->pages() as $page) {
                // The HARD gate: an inactive feature has no page and no route, so a
                // disabled module cannot be reached by typing the URL either.
                if ($page->feature !== null && ! Console::featureActive($page->feature)) {
                    continue;
                }

                // A WORKSPACE'S CONSOLE is the workspace, its team's sign-in and its log —
                // see WorkspaceAltitude for why the rest is withheld rather than refused.
                if ($workspace && ! WorkspaceAltitude::keepsPage($area->key, $page->route)) {
                    $offRail = $offRail || $this->routeIsCurrent($page->route, $claimed);

                    continue;
                }

                // A CUSTOMER'S CONSOLE is an admin portal and the person's own pages — see
                // CustomerConsole. Withheld AND refused: the same list answers 404 at the
                // door, so there is no "reached by URL" case to put a notice above.
                if ($customer && ! CustomerConsole::keepsPage($area->key, $page->route)) {
                    continue;
                }

                $feature = self::ENTITLEMENT_FEATURE[$page->route] ?? null;

                $pages[] = new NavPageProps(
                    route: $page->route,
                    href: route($page->route),
                    label: $page->label,
                    active: $this->routeIsCurrent($page->route, $claimed),
                    badge: $feature !== null && ! $this->entitlements->entitledOrgFeature($feature)
                        ? 'Enterprise'
                        : null,
                    keywords: ConsoleSynonyms::for($page->route),
                );
            }

            // An area with nothing left in it is not an area. This is what makes a
            // capability absent rather than gated on a plane that does not serve it.
            if ($pages === []) {
                continue;
            }

            $areas[] = new NavAreaProps(
                key: $area->key,
                label: $workspace ? WorkspaceAltitude::label($area->key, $area->label) : $area->label,
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

        $areas = $this->markActive($areas, fallback: ! $offRail);
        $active = $this->activeArea($areas);

        return new ShellProps(
            areas: $areas,
            activeArea: $active?->key,
            section: $platformMode ? 'Platform' : null,
            context: $this->context->forOrganizationPlane($this->activePageRoute($active)),
            isOperator: $isOperator,
            platformMode: $platformMode,
            // A workspace's home is Projects; `dashboard` would hand it straight on to an
            // environment, which is not what clicking the brand mark in its own console
            // means. In platform admin the brand mark stays in platform admin.
            brandHref: route(match (true) {
                $platformMode => 'platform.workspaces',
                $workspace => 'projects',
                default => 'dashboard',
            }),
            navPinned: NavPin::pinned($this->request),
            accountHref: route('account'),
            switchUserHref: route('accounts'),
            altitude: $workspace ? ConsoleAltitude::Workspace : ConsoleAltitude::Organization,
            workspaceSettingsHref: $this->scope->membershipRole() !== null
                && $this->scope->capabilities()?->canManageMembers() === true
                && Route::has('organization-settings')
                    ? route('organization-settings')
                    : null,
            platformHref: $isOperator ? route('platform.workspaces') : null,
            exitPlatformHref: $platformMode ? route('dashboard') : null,
            notice: $offRail ? new ShellNoticeProps(
                message: 'This page manages your workspace’s own record in Cbox — the team that signs in to this console — not your product. Your apps, users and roles live in each environment’s console.',
                href: route('projects'),
                label: 'Go to Projects',
            ) : null,
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
        $membership = app(EnvironmentAdminAuth::class)->membership();

        if ($membership === null) {
            return null;
        }

        $nav = $this->navigation->environment();
        $areas = $this->withWaitingApprovals($this->markActive($this->fromConsoleNav($nav)));
        $active = $this->activeArea($areas);

        return new ShellProps(
            areas: $areas,
            activeArea: $active?->key,
            // The environment console is already one environment's, and its name is in
            // the topbar. A second word in the tab title would say nothing.
            section: null,
            // THE WAY TO EVERY OTHER ENVIRONMENT, and back to the workspace. This console
            // had a back arrow and the environment's name as plain text; moving from
            // staging to production meant going back to Projects on another host and
            // opening it again.
            context: $this->context->forEnvironmentPlane($membership, $this->activePageRoute($active)),
            isOperator: false,
            platformMode: false,
            brandHref: $areas === [] ? route('environment.home') : $areas[0]->href,
            navPinned: NavPin::pinned($this->request),
            accountHref: $this->context->onWorkspaceHost('account'),
            switchUserHref: $this->context->onWorkspaceHost('accounts'),
            altitude: ConsoleAltitude::Environment,
            workspaceSettingsHref: OrganizationCapabilities::of($membership->role)->canManageMembers()
                ? $this->context->onWorkspaceHost('organization-settings')
                : null,
        );
    }

    /**
     * Put the number of agent actions waiting for THIS person beside Approvals.
     *
     * Only theirs: an approval is answered by the person the agent's key belongs to, so a
     * count of everybody's would be a number most readers can do nothing about. One indexed
     * read on most pages (nothing young enough to wait answers it), two when something is.
     *
     * @param  list<NavAreaProps>  $areas
     * @return list<NavAreaProps>
     */
    private function withWaitingApprovals(array $areas): array
    {
        $auth = app(EnvironmentAdminAuth::class);
        $subjectId = $auth->subjectId();
        $environmentId = $auth->environmentId();

        if ($subjectId === null || $environmentId === null) {
            return $areas;
        }

        $waiting = $this->approvals->waitingFor($subjectId, $environmentId);

        if ($waiting === 0) {
            return $areas;
        }

        return array_map(static fn (NavAreaProps $area): NavAreaProps => new NavAreaProps(
            key: $area->key,
            label: $area->label,
            icon: $area->icon,
            href: $area->href,
            active: $area->active,
            current: $area->current,
            pages: array_map(static fn (NavPageProps $page): NavPageProps => $page->route === 'environment.approvals'
                ? new NavPageProps($page->route, $page->href, $page->label, $page->active, $page->badge, $waiting, $page->keywords)
                : $page, $area->pages),
        ), $areas);
    }

    /**
     * The nav page this request lights, so the context switcher can land on the same page
     * in another environment when the page itself carries an entity in its URL.
     */
    private function activePageRoute(?NavAreaProps $area): ?string
    {
        foreach ($area->pages ?? [] as $page) {
            if ($page->active) {
                return $page->route;
            }
        }

        return null;
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
                        keywords: ConsoleSynonyms::for($page->route),
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
    private function markActive(array $areas, bool $fallback = true): array
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
        //
        // EXCEPT for a page the rail deliberately does not offer (a workspace console on
        // one of its end-user pages): lighting "Workspace" above it, with Workspace's
        // sub-nav beside it and "Workspace" as its eyebrow, would claim the page is part
        // of the one area it is explicitly not.
        if ($fallback) {
            $activeKey ??= $areas[0]->key ?? null;
        }

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
     *
     * NOR ON A PAGE OF ITS OWN BELOW IT. `account` (Security) is a prefix of
     * `account.activity` and `account.api-keys`, which are pages beside it rather than
     * details of it — and the prefix test lit Security as well as the page being shown,
     * two items at once in a three-item sub-nav. A route that a more specific page claims
     * belongs to that page.
     *
     * @param  list<string>  $claimed  every page route on the rail
     */
    private function routeIsCurrent(string $route, array $claimed = []): bool
    {
        if ($this->request->routeIs($route)) {
            return true;
        }

        if (! $this->request->routeIs($route.'.*')) {
            return false;
        }

        foreach ($claimed as $other) {
            if (str_starts_with($other, $route.'.')
                && ($this->request->routeIs($other) || $this->request->routeIs($other.'.*'))) {
                return false;
            }
        }

        return true;
    }
}
