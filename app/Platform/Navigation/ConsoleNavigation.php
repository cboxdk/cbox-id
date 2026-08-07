<?php

declare(strict_types=1);

namespace App\Platform\Navigation;

use App\Platform\Console\ConsoleArea;
use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsolePlane;
use App\Platform\ConsoleLocation;
use App\Platform\Health\ConsoleParityHealthCheck;
use App\Providers\ConsoleServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * The navigation of the environment plane and the platform section — everything except
 * the organization console, which is assembled at runtime from the plugin registry (see
 * {@see ConsoleLocation}).
 *
 * The layouts render from these; so does the eyebrow above every page title, so the two
 * cannot disagree. Adding a page means adding it here, once.
 */
class ConsoleNavigation
{
    private ?ConsolePages $pages = null;

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
                // …and its pair keeps the pair's other half. The page, its detail view,
                // its help topic and the organization plane's registry entry all say
                // "Sync users out"; only this line said "Outbound sync", which is the
                // name the line above was renamed AWAY from. Found by the extended
                // ConsoleAreasTest, not by reading — which is the point of that test.
                new NavPage('environment.provisioning', 'Sync users out'),
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
                // "Token vault" on both planes now. One component serves them, so it has
                // one title — and clicking "Stored tokens" to land on a page headed
                // "Token vault" is the same broken promise the merged pairs above fixed.
                new NavPage('environment.vault', 'Token vault'),
            ),
            new NavArea('Logs', 'audit',
                // "Activity log" — what the page, its help topic and the organization
                // plane's registry entry all call it. Same drift as Sync users out.
                new NavPage('environment.audit', 'Activity log'),
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
     * The platform section — whoever runs this deployment, above every account.
     *
     * These were a console of their own — separate prefix, separate layout, separate
     * sign-in — and the separation was never a security boundary. It was a consequence of
     * `platform_operators` being a second credential store: with no way to ask "is this
     * session staff", the only way to gate the pages was to put a different door in front
     * of them. The operator is a subject now, so the question has an answer.
     *
     * This used to be folded into the account console's rail as well, for a person who
     * held both. There is no account console: those pages are an area of the ONE console
     * ({@see ConsoleServiceProvider}'s `identity-platform`), gated on what
     * the acting organization owns rather than on which shell rendered them. What is left
     * here is one section, in one shell, and it is the only thing this class still
     * describes besides the environment plane.
     */
    public function operator(): ConsoleNav
    {
        return new ConsoleNav(
            // ROOT FIRST, then leaf. The hierarchy here is account → project →
            // environment, and this list used to read the other way: Environments,
            // Customers, Organizations — the leaf, then the root, then a tenant inside
            // the leaf. An operator opening the console was handed six planes called
            // `production`, `staging`, `acme`, `acme-staging`, `billing-portal` and
            // `demo-co` and no way to tell that `billing-portal` is Acme's.
            //
            // The account is the object at this altitude: it is the customer, and every
            // environment on the install is a detail inside one (or, for exactly two
            // rows, deliberately inside none — see {@see \App\Platform\Console\EnvironmentLineage}).
            new NavArea('Platform', 'layers',
                new NavPage('platform.customers', 'Customers'),
                new NavPage('platform.environments', 'Environments'),
                new NavPage('platform.organizations', 'Organizations'),
            ),
            // Icons distinct at 18px, which is the only size the collapsed rail renders
            // them at. Insights and Platform were both `dashboard` and `layers` when
            // these three stood beside the account areas in one rail, which is two pairs
            // of near-identical glyphs in a control whose entire job is to be told apart
            // at a glance. Insights takes `chart` and Administration takes `lock`.
            new NavArea('Insights', 'chart',
                new NavPage('platform.usage', 'Usage'),
                new NavPage('platform.search', 'Search'),
            ),
            new NavArea('Administration', 'lock',
                new NavPage('platform.operators', 'Operators'),
                new NavPage('platform.security', 'Security'),
            ),
        );
    }

    /**
     * Every navigation this class describes, for the code that has only a route name and
     * needs to find which area owns it.
     *
     * The organization plane is deliberately absent and always was: it is assembled from
     * the plugin registry, so {@see ConsoleLocation} reads it through `Console::nav()` and
     * consults this only for what the registry cannot answer.
     *
     * @return list<ConsoleNav>
     */
    public function all(): array
    {
        return [
            $this->environment(),
            $this->operator(),
        ];
    }
}
