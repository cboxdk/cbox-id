<?php

declare(strict_types=1);

namespace App\Platform\Navigation;

use App\Platform\Console\ConsoleArea;
use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\ConsoleLocation;
use App\Platform\Health\ConsoleParityHealthCheck;
use Cbox\Id\Platform\Enums\AccountRole;
use Illuminate\Support\Facades\Route;

/**
 * The navigation of every plane except the organization console, which is assembled at
 * runtime from the plugin registry (see {@see ConsoleLocation}).
 *
 * The layouts render from these; so does the eyebrow above every page title, so the two
 * cannot disagree. Adding a page means adding it here, once.
 */
class ConsoleNavigation
{
    private ?ConsolePages $pages = null;

    /**
     * The workspace (account) console — what an account member sees before choosing an
     * environment.
     *
     * Role-aware: a member who cannot read billing has no Billing page, and an area
     * left with no pages disappears rather than rendering an empty rail icon. Null role
     * means no member is resolved yet, which shows only the pages that need no
     * permission at all.
     */
    public function workspace(?AccountRole $role): ConsoleNav
    {
        $areas = [
            ...$this->platformAreas(),
            new NavArea('Overview', 'dashboard',
                new NavPage('workspace.home', 'Projects'),
            ),
            new NavArea('People', 'members',
                ...($role?->canReadMembers() === true ? [new NavPage('workspace.members', 'Members')] : []),
            ),
            new NavArea('Developers', 'clients',
                ...($role?->canManageMembers() === true ? [new NavPage('workspace.api-keys', 'API keys')] : []),
                ...($role?->canManageEnvironments() === true ? [
                    new NavPage('workspace.environment-keys', 'Environment keys'),
                    new NavPage('workspace.environment-domains', 'Domains'),
                ] : []),
            ),
            new NavArea('Account', 'settings',
                ...($role?->canReadMembers() === true ? [new NavPage('workspace.activity', 'Activity')] : []),
                ...($role?->canReadBilling() === true ? [new NavPage('workspace.billing', 'Billing')] : []),
                ...($role?->canManageMembers() === true ? [new NavPage('workspace.settings', 'Settings')] : []),
            ),
            // The member's OWN identity, 2FA and passkeys — a personal concern rather
            // than an account setting, so it gets its own area instead of hiding at the
            // bottom of Settings.
            new NavArea('Personal', 'shield-check',
                new NavPage('workspace.security', 'Profile'),
            ),
        ];

        return new ConsoleNav(...array_filter($areas, fn (NavArea $area): bool => $area->pages !== []));
    }

    /**
     * The environment control plane — an account-member admin's view of ONE
     * environment. Every resource here is environment-scoped.
     *
     * The written list below is the console's OWN pages. Module pages are merged in from
     * {@see ConsolePages} rather than written here, because a module is installed rather
     * than edited in — and because the organization rail already assembles itself from a
     * registry, so a module that had to be added to a hand-written list on one plane and
     * a registry on the other would go on being added to one of them.
     */
    public function environment(): ConsoleNav
    {
        return new ConsoleNav(...$this->withModulePages([
            new NavArea('Overview', 'dashboard',
                new NavPage('environment.home', 'Overview'),
                new NavPage('environment.analytics', 'Analytics'),
                new NavPage('environment.approvals', 'Agent approvals'),
            ),
            new NavArea('Tenants', 'layers',
                new NavPage('environment.organizations', 'Organizations'),
            ),
            new NavArea('People', 'members',
                new NavPage('environment.users', 'Users'),
                new NavPage('environment.roles', 'Roles'),
                new NavPage('environment.permissions', 'Permissions'),
            ),
            new NavArea('Sign-in', 'connections',
                new NavPage('environment.connections', 'Single sign-on'),
                new NavPage('environment.social-providers', 'Social sign-in'),
                new NavPage('environment.sso-providers', 'Login methods'),
                // One component serves both planes now, so it has one title — and the
                // organization plane's "Sync users in" is the name the help topic and the
                // published guide already use. "Directories" also said nothing about
                // which direction people move, one line above Outbound sync.
                new NavPage('environment.directories', 'Sync users in'),
                new NavPage('environment.provisioning', 'Outbound sync'),
            ),
            new NavArea('Access control', 'shield-check',
                new NavPage('environment.governance', 'Access reviews'),
                // One component serves both planes now, so it has one title — and the
                // organization plane's "Role conflicts" is the name the help topic and
                // the published guide already use.
                new NavPage('environment.sod-policies', 'Role conflicts'),
            ),
            new NavArea('Developers', 'clients',
                // One component serves both planes now, so it has one title — and the
                // organization plane's "Apps & API keys" is the name the help topic and
                // the published guide already use. It also names the half "Applications"
                // hides: the machine credentials that never sign anyone in.
                new NavPage('environment.clients', 'Apps & API keys'),
                new NavPage('environment.webhooks', 'Webhooks'),
                // "Inline hooks" on both planes now. Called "Event hooks" here, it sat
                // one line under Webhooks — a different capability that runs after the
                // fact — and named the synchronous one after the asynchronous one.
                new NavPage('environment.hooks', 'Inline hooks'),
                new NavPage('environment.vault', 'Stored tokens'),
            ),
            new NavArea('Logs', 'audit',
                new NavPage('environment.audit', 'Audit log'),
                new NavPage('environment.audit-streams', 'Log streaming'),
            ),
            new NavArea('Settings', 'settings',
                new NavPage('environment.settings', 'Settings'),
                new NavPage('environment.auth-policy', 'Sign-in rules'),
                new NavPage('environment.appearance', 'Appearance'),
            ),
        ]));
    }

    /**
     * Merge the module-declared environment-plane pages into the written rail.
     *
     * A page lands in the area its {@see ConsoleArea} names on this plane; an area no
     * module page reaches is returned untouched, and an area the environment console does
     * not have yet (Connectors) is inserted where {@see ConsoleArea::environmentAfter()}
     * says, so the two rails read in the same order.
     *
     * Pages whose route is missing are dropped rather than rendered. That is the one
     * place this file is deliberately quiet: the rail renders on EVERY page of the plane,
     * so a module that declared both planes and routed one would take the whole
     * environment console down with a RouteNotFoundException. The
     * {@see ConsoleParityHealthCheck} reports exactly that case, which
     * is where a missing route should be loud — in the doctor, not in a 500 on every page.
     *
     * @param  list<NavArea>  $areas
     * @return list<NavArea>
     */
    private function withModulePages(array $areas): array
    {
        /** @var array<string, list<NavPage>> $additions */
        $additions = [];
        /** @var array<string, ConsoleArea> $introduced */
        $introduced = [];

        foreach ($this->pages()->forPlane(ConsolePlane::Environment) as $page) {
            $label = $page->area->environmentLabel();
            $route = $page->routeOn(ConsolePlane::Environment);

            if ($label === null || ! Route::has($route)) {
                continue;
            }

            $additions[$label][] = new NavPage($route, $page->label);
            $introduced[$label] = $page->area;
        }

        if ($additions === []) {
            return $areas;
        }

        $merged = [];

        foreach ($areas as $area) {
            $pages = $additions[$area->label] ?? [];
            unset($additions[$area->label]);

            $merged[] = $pages === []
                ? $area
                : new NavArea($area->label, $area->icon, ...$area->pages, ...$pages);

            // A module-introduced area sits immediately after the one it names, so
            // Connectors lands between Developers and Logs on both rails.
            foreach ($additions as $label => $pages) {
                if ($introduced[$label]->environmentAfter() === $area->label) {
                    $merged[] = new NavArea($label, $introduced[$label]->environmentIcon(), ...$pages);
                    unset($additions[$label]);
                }
            }
        }

        // Anything left names no neighbour (or names one this plane does not have): it
        // goes at the end rather than being dropped, so a page is never silently absent.
        foreach ($additions as $label => $pages) {
            $merged[] = new NavArea($label, $introduced[$label]->environmentIcon(), ...$pages);
        }

        return $merged;
    }

    /**
     * Resolved lazily rather than injected, because this class is constructed directly —
     * `new ConsoleNavigation` — by the tests that assert the rail's invariants, and a
     * constructor dependency would make the nav's own description unreadable without a
     * container.
     */
    private function pages(): ConsolePages
    {
        return $this->pages ??= app(ConsolePages::class);
    }

    /**
     * The operator plane — whoever runs this deployment, above every account.
     *
     * Kept as its own method for the route-to-area lookup in {@see all()}, which needs to
     * know where a page lives regardless of who may see it. The RAIL no longer comes from
     * here: {@see platformAreas()} folds these into the one console.
     */
    public function operator(): ConsoleNav
    {
        return new ConsoleNav(...$this->platformAreaList());
    }

    /**
     * The platform areas, shown only to whoever runs the deployment.
     *
     * These were a separate console — separate prefix, separate layout, separate sign-in —
     * and the separation was never a security boundary. It was a consequence of
     * `platform_operators` being a second credential store: with no way to ask "is this
     * session staff", the only way to gate the pages was to put a different door in front
     * of them. The operator is a subject now, so the question has an answer, and the pages
     * become areas that appear for whoever may see them — which is how every other area in
     * this rail has always worked.
     *
     * Placed FIRST rather than last. An operator's reason for opening the console is
     * usually the platform, not their own account's projects; burying the platform under
     * the personal areas would make the merged rail worse for the only people who see it.
     *
     * Asked of {@see ConsoleScope}, not of a flag threaded in by the caller: three layouts
     * and a middleware would each have had to be handed the same answer, and an
     * authorization invariant kept by hand across four call sites is the shape of every
     * divergence this console has already been through.
     *
     * @return list<NavArea>
     */
    private function platformAreas(): array
    {
        return app(ConsoleScope::class)->isPlatformOperator() ? $this->platformAreaList() : [];
    }

    /** @return list<NavArea> */
    private function platformAreaList(): array
    {
        return [
            new NavArea('Platform', 'layers',
                new NavPage('platform.environments', 'Environments'),
                new NavPage('platform.accounts', 'Accounts'),
                new NavPage('platform.organizations', 'Organizations'),
            ),
            new NavArea('Insights', 'dashboard',
                new NavPage('platform.usage', 'Usage'),
                new NavPage('platform.search', 'Search'),
            ),
            new NavArea('Administration', 'shield',
                new NavPage('platform.operators', 'Operators'),
                new NavPage('platform.security', 'Security'),
            ),
        ];
    }

    /**
     * Every plane's navigation, for the code that has only a route name and needs to
     * find which plane owns it. The workspace nav is taken at its widest role, because
     * the question being asked is "where does this page live", not "may you see it".
     *
     * @return list<ConsoleNav>
     */
    public function all(): array
    {
        return [
            $this->workspace(AccountRole::Owner),
            $this->environment(),
            $this->operator(),
        ];
    }
}
