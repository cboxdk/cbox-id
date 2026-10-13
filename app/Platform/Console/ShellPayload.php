<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Props\Shell\NavAreaProps;
use App\Http\Props\Shell\NavPageProps;
use App\Http\Props\Shell\ShellActsOnProps;
use App\Http\Props\Shell\ShellProps;
use App\Platform\Agents\ActionApprovalInbox;
use App\Platform\CurrentEnvironment;
use App\Platform\CurrentUser;
use App\Platform\Entitlements;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Navigation\ConsoleNav;
use App\Platform\Navigation\ConsoleNavigation;
use App\Platform\OrganizationCapabilities;
use App\Platform\PlaneResolver;
use App\Providers\ConsoleServiceProvider;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
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
     *  - `platform`, because this gate asks whether you administer the organization you
     *    are currently ACTING FOR, and an operator's authority has nothing to do with that.
     *    The person who runs the install is routinely a plain member of the customer they
     *    are looking at, so leaving them to this gate hides platform admin from exactly the
     *    people it exists for. Their own `platform.operator` feature is the stronger gate
     *    and has already run: the area survives to here only if its pages did.
     */
    private const MEMBER_AREAS = [
        'overview',
        'account',
        'identity-platform',
        self::PLATFORM_AREA,
    ];

    /**
     * The registry area holding platform admin — the pages about the whole install rather
     * than one customer on it ({@see ConsoleServiceProvider::platformAreas()}).
     */
    private const PLATFORM_AREA = 'platform';

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
        private PlaneResolver $planes,
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

        $shell = match (true) {
            $this->onPlatformAdmin() => $this->platformAdminShell(),
            $this->scope->plane() === ConsolePlane::Environment => $this->environmentShell(),
            default => $this->organizationShell(),
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

        // A page of platform admin, drawn in this console — a single-tenant install's, whose
        // rail carries the Platform area for its operators. (On a multi-tenant deployment
        // these pages are drawn by the platform environment's console instead; see
        // onPlatformAdmin().) Not a mode any more: the same rail, with the line above the
        // page saying it acts on the whole deployment.
        $platformPage = $this->request->routeIs('platform.*');

        // The organization console on the platform root's host is a workspace's console,
        // for everybody: see RootHostConsole for what it keeps and where the rest went —
        // platform admin included, which is an area of the platform environment's console.
        $rootHost = $this->planes->onAccountPlane();

        $areas = [];

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

            $pages = [];

            foreach ($area->pages() as $page) {
                // The HARD gate: an inactive feature has no page and no route, so a
                // disabled module cannot be reached by typing the URL either.
                if ($page->feature !== null && ! Console::featureActive($page->feature)) {
                    continue;
                }

                // A WORKSPACE'S CONSOLE is the workspace, its team's sign-in and its log — and
                // on the platform root's host that is the console, for everybody. The rest is
                // set in an environment's console; see RootHostConsole.
                if ($rootHost && ! RootHostConsole::railKeeps($area->key, $page->route, $isOperator)) {
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
                label: $rootHost ? WorkspaceAltitude::label($area->key, $area->label) : $area->label,
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
            section: $platformPage ? Vocabulary::PLATFORM : null,
            context: $this->context->forOrganizationPlane($this->activePageRoute($active)),
            isOperator: $isOperator,
            // A workspace's home is Projects; `dashboard` would hand it straight on to an
            // environment, which is not what clicking the brand mark in its own console
            // means.
            brandHref: route($workspace ? 'projects' : 'dashboard'),
            navPinned: NavPin::pinned($this->request),
            accountHref: route('account'),
            switchUserHref: route('accounts'),
            altitude: $workspace ? ConsoleAltitude::Workspace : ConsoleAltitude::Organization,
            workspaceSettingsHref: $this->scope->membershipRole() !== null
                && $this->scope->capabilities()?->canManageMembers() === true
                && Route::has('organization-settings')
                    ? route('organization-settings')
                    : null,
            // Platform admin is in ANOTHER console here — the platform environment's, at
            // `/admin` on this host — so the account menu is the way there. On a
            // single-tenant install it is in this rail, and a second link would be a
            // second way to the same place.
            platformHref: $isOperator && $rootHost && Route::has('platform.workspaces') ? route('platform.workspaces') : null,
            actsOn: $this->organizationActsOn($platformPage, $workspace),
            // The platform's own environment console, beside platform admin in the account
            // menu: an operator at the root is one click from the environment they run.
            platformEnvironmentHref: $isOperator && $rootHost && Route::has('environment.home') ? route('environment.home') : null,
        );
    }

    /**
     * Whether this request is PLATFORM ADMIN drawn inside the platform environment's
     * console: a platform page (`/admin/platform/…`) on the root's own host of a
     * multi-tenant deployment.
     *
     * Asked of the route and the HOST, not of {@see ConsoleScope::plane()} or the ambient
     * environment, and both on purpose. The platform pages run the subject gate rather than
     * the environment console's (see routes/web.php), so the plane answers "organization";
     * and they READ whichever environment the operator has pointed them at, so the ambient
     * environment is often a tenant's. Neither says which console the operator is standing
     * in. The host does: on the root's host, `/admin` is the platform environment's console
     * and these pages are its Platform area.
     */
    private function onPlatformAdmin(): bool
    {
        return $this->request->routeIs('platform.*')
            && $this->planes->isMultiTenant()
            && $this->planes->isPlatformRootHost($this->request->getHost())
            && $this->scope->isPlatformOperator();
    }

    /**
     * PLATFORM ADMIN, in the platform environment's console — the same rail, context and
     * account menu as `/admin`'s own pages, with the Platform area lit and the line above
     * the page saying it acts on the whole deployment.
     *
     * The operator is the signed-in subject here (the environment console's own gate, which
     * would say so, is not on these routes), and the environment the console stands in is
     * the platform root's — named explicitly rather than read from the request, which
     * carries the environment the operator is READING.
     */
    private function platformAdminShell(): ?ShellProps
    {
        $subjectId = $this->scope->operator()?->subject_id;

        if (! is_string($subjectId) || $subjectId === '') {
            return null;
        }

        $rootId = app(PlatformRoot::class)->environment()?->environmentKey();

        return $this->environmentConsole(
            subjectId: $subjectId,
            membership: null,
            rootOperator: true,
            environmentId: is_string($rootId) ? $rootId : null,
            actsOn: $this->platformActsOn(),
            section: Vocabulary::PLATFORM,
        );
    }

    /**
     * The Platform area as the environment console draws it — every page of the registry's
     * `platform` area, for somebody already established as an operator.
     *
     * The registry's `platform.operator` feature is not asked again: it answers through the
     * SUBJECT session, and on the environment console's own pages the person is an
     * environment administrator instead ({@see ConsoleScope::actingSubjectId()}), so it
     * would say "no" to the very operator the console's gate just admitted. The caller has
     * already asked the stronger question.
     *
     * @param  list<string>  $claimed  every page route on the rail, for {@see routeIsCurrent()}
     * @return list<NavAreaProps>
     */
    private function platformArea(array $claimed): array
    {
        foreach (Console::nav()->areas() as $area) {
            if ($area->key !== self::PLATFORM_AREA) {
                continue;
            }

            $pages = [];

            foreach ($area->pages() as $page) {
                $claimed[] = $page->route;
            }

            foreach ($area->pages() as $page) {
                if ($page->feature !== null && $page->feature !== 'platform.operator' && ! Console::featureActive($page->feature)) {
                    continue;
                }

                $pages[] = new NavPageProps(
                    route: $page->route,
                    href: route($page->route),
                    label: $page->label,
                    active: $this->routeIsCurrent($page->route, $claimed),
                    keywords: ConsoleSynonyms::for($page->route),
                );
            }

            return $pages === [] ? [] : [new NavAreaProps(
                key: $area->key,
                label: $area->label,
                icon: $area->icon ?? 'rocket',
                href: $pages[0]->href,
                active: false,
                current: false,
                pages: $pages,
            )];
        }

        return [];
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
        $auth = app(EnvironmentAdminAuth::class);
        $subjectId = $auth->subjectId();

        if ($subjectId === null) {
            return null;
        }

        // A tenant's administrator, through their workspace's membership — or, on the
        // platform root's own console, one of the operators who run the deployment.
        $operator = $auth->admitsRootOperator();

        return $this->environmentConsole(
            subjectId: $subjectId,
            membership: $auth->membership(),
            rootOperator: $operator,
            environmentId: $auth->environmentId(),
            actsOn: $this->environmentActsOn($operator),
            section: null,
        );
    }

    /**
     * The environment console's chrome, for whoever administers it — and, on the platform
     * root's own console, with the PLATFORM area first: platform admin is part of this
     * console there, not a console of its own.
     *
     * First, because on the root this console is what the topbar's first crumb calls
     * "Platform admin": the brand mark and a fresh sign-in land on Workspaces, and the
     * environment's own areas follow it.
     */
    private function environmentConsole(
        string $subjectId,
        ?Membership $membership,
        bool $rootOperator,
        ?string $environmentId,
        ShellActsOnProps $actsOn,
        ?string $section,
    ): ShellProps {
        // A workspace member on a tenant's console who also runs the deployment: platform
        // admin and the platform's own environment stay in their account menu.
        $tenantOperator = ! $rootOperator && $this->scope->subjectIsOperator($subjectId);

        $nav = $this->navigation->environment();
        $environmentAreas = $this->fromConsoleNav($nav);
        $platform = $rootOperator ? $this->platformArea($nav->routes()) : [];

        $areas = $this->withWaitingApprovals($this->markActive(
            [...$platform, ...$environmentAreas],
            fallbackKey: $this->request->routeIs('platform.*') ? ($platform[0]->key ?? null) : ($environmentAreas[0]->key ?? null),
        ), $subjectId, $environmentId);
        $active = $this->activeArea($areas);

        return new ShellProps(
            areas: $areas,
            activeArea: $active?->key,
            // The environment console is already one environment's, and its name is in
            // the topbar. A second word in the tab title would say nothing — except on a
            // page of platform admin, which is about every environment at once.
            section: $section,
            // THE WAY TO EVERY OTHER ENVIRONMENT, and back to the workspace. This console
            // had a back arrow and the environment's name as plain text; moving from
            // staging to production meant going back to Projects on another host and
            // opening it again.
            context: $this->context->forEnvironmentPlane($membership, $subjectId, $this->activePageRoute($active)),
            isOperator: $rootOperator,
            brandHref: $areas === [] ? route('environment.home') : $areas[0]->href,
            navPinned: NavPin::pinned($this->request),
            accountHref: $this->context->onWorkspaceHost('account'),
            switchUserHref: $this->context->onWorkspaceHost('accounts'),
            altitude: ConsoleAltitude::Environment,
            workspaceSettingsHref: $membership !== null && OrganizationCapabilities::of($membership->role)->canManageMembers()
                ? $this->context->onWorkspaceHost('organization-settings')
                : null,
            // Platform admin is one click away from the console of the environment a tenant
            // operator is standing in. On the root's own console it is in the rail.
            platformHref: $tenantOperator ? $this->context->onWorkspaceHost('platform.workspaces') : null,
            actsOn: $actsOn,
            platformEnvironmentHref: $tenantOperator ? $this->context->onWorkspaceHost('environment.home') : null,
            // A tenant's console signs out of the ADMIN context, not the tenant's end-user
            // session that shares this host. On the platform root's own console the admin
            // context IS the root's session, which `/logout` ends.
            logoutHref: $rootOperator ? route('logout') : route('admin.logout'),
        );
    }

    /**
     * What a page of the environment console acts on: this environment — and, on the
     * platform root's own console, a sentence saying whose sign-in that is.
     */
    private function environmentActsOn(bool $root): ShellActsOnProps
    {
        $name = $this->environmentName() ?? 'This environment';
        $host = $this->request->getHost();

        if ($root) {
            return new ShellActsOnProps(
                kind: 'environment',
                label: $name,
                detail: 'The platform’s own environment on '.$host.': how every workspace owner and every operator signs in to Cbox ID, and the sign-in for every environment console. A change here applies to all of them.',
                critical: true,
            );
        }

        return new ShellActsOnProps(kind: 'environment', label: $name, detail: 'Environment on '.$host);
    }

    /**
     * What a page of platform admin acts on: the whole deployment — said in the warning
     * colours, because a suspension or a new operator here reaches every workspace on it.
     *
     * This line is what the amber strip across the top of platform admin used to say, when
     * platform admin was a console of its own. Every console page has the line now, so the
     * warning is where every other page says what it acts on, rather than in a layout only
     * these pages had.
     */
    private function platformActsOn(): ShellActsOnProps
    {
        return new ShellActsOnProps(
            kind: 'platform',
            label: 'The whole deployment',
            detail: 'Every workspace, environment and operator on '.$this->request->getHost().'. A suspension, a new environment or an operator change here applies across all of them.',
            critical: true,
        );
    }

    /** What a page of the organization console acts on: the deployment, a workspace or an organization. */
    private function organizationActsOn(bool $platformPage, bool $workspace): ShellActsOnProps
    {
        if ($platformPage) {
            return $this->platformActsOn();
        }

        $name = $this->user->organization()->name ?? 'Your organization';

        if ($workspace) {
            // Team sign-in says whose sign-in it is: the workspace's own team, signing in to
            // Cbox ID — the one sign-in setting on this host, and the one easiest to mistake
            // for the product's.
            $route = (string) $this->request->route()?->getName();
            $teamSignIn = (CustomerConsole::pageOf($route)[0] ?? null) === 'authentication' && RootHostConsole::keepsRoute($route);

            return new ShellActsOnProps(
                kind: 'workspace',
                label: $name,
                detail: $teamSignIn
                    ? 'How your workspace’s own team signs in to Cbox ID — not your apps: their sign-in is set in each environment’s console.'
                    : 'Your workspace: its projects, team, keys and billing. Sign-in for your apps is set in each environment’s console.',
            );
        }

        if ($this->scope->ownsIdentityProviders()) {
            return new ShellActsOnProps(kind: 'workspace', label: $name, detail: 'Workspace');
        }

        $environment = $this->environmentName();

        return new ShellActsOnProps(
            kind: 'organization',
            label: $name,
            detail: $environment === null ? 'Organization' : 'Organization in '.$environment,
        );
    }

    /** The name of the environment this request stands in, or null — read once per request. */
    private function environmentName(): ?string
    {
        return app(CurrentEnvironment::class)->name();
    }

    /**
     * Put the number of agent actions waiting for THIS person beside Approvals.
     *
     * Only theirs: an approval is answered by the person the agent's key belongs to, so a
     * count of everybody's would be a number most readers can do nothing about. One indexed
     * read on most pages (nothing young enough to wait answers it), two when something is.
     *
     * The environment is NAMED rather than read from the request: on a page of platform
     * admin the request carries the environment the operator is reading, and the
     * Approvals on this rail are the platform environment's.
     *
     * @param  list<NavAreaProps>  $areas
     * @return list<NavAreaProps>
     */
    private function withWaitingApprovals(array $areas, string $subjectId, ?string $environmentId): array
    {
        if ($environmentId === null) {
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
    private function markActive(array $areas, bool $fallback = true, ?string $fallbackKey = null): array
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
        //
        // The FIRST area unless the caller names another: on the platform root's console
        // the first area is Platform, and an environment page nobody listed belongs to the
        // environment's own areas, not to platform admin.
        if ($fallback) {
            $activeKey ??= $fallbackKey ?? $areas[0]->key ?? null;
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
