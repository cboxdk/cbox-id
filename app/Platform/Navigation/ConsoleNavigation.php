<?php

declare(strict_types=1);

namespace App\Platform\Navigation;

use App\Platform\Console\ConsoleArea;
use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\Vocabulary;
use App\Platform\ConsoleLocation;
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
        // THE MARKET'S WORDS, in the order a team sets an IdP up. The rail used to be
        // grouped by how this codebase is organized — "Sign-in" holding both directions
        // of SAML and both directions of SCIM, "Developers" holding the token vault and
        // the legacy password import — so a person who had used another identity
        // platform looked for "Enterprise SSO" and "Directory Sync" and found neither
        // word. The pages and their URLs are unchanged; this is only where they are filed
        // and what they are called. What is rarely touched after setup is under Advanced,
        // so the areas above it are the ones a team opens every week.
        return new ConsoleNav(...$this->withModulePages([
            new NavArea('Home', 'home',
                new NavPage('environment.home', 'Overview'),
                // The guided first run: framework → app → first sign-in, and the checklist.
                new NavPage('environment.get-started', 'Get started'),
            ),
            new NavArea('Users & orgs', 'members',
                new NavPage('environment.users', Vocabulary::USERS),
                // Not "Tenants": that is the word other platforms use for what we call an
                // environment, so it pointed the wrong way for exactly the readers most
                // likely to need it.
                new NavPage('environment.organizations', Vocabulary::ORGANIZATIONS),
                new NavPage('environment.roles', Vocabulary::ROLES),
                // Roles are made OF permissions, so a console that offers one and hides
                // the other asks an administrator to assign a thing they cannot inspect.
                new NavPage('environment.permissions', Vocabulary::PERMISSIONS),
                // Who may do what to which of the app's OWN resources — the model the app
                // defines and checks per request, beside the roles a person holds.
                new NavPage('environment.fga', Vocabulary::FINE_GRAINED_AUTHORIZATION),
            ),
            // How people arrive — every page here is a way IN. The outbound halves (SAML
            // apps that trust this environment, provisioning out to other systems) are
            // under Advanced, so "SSO" and "sync" each mean one direction on this rail.
            new NavArea('Authentication', 'fingerprint',
                // The password, MFA and session policy.
                new NavPage('environment.auth-policy', Vocabulary::AUTHENTICATION_POLICY),
                new NavPage('environment.social-providers', Vocabulary::SOCIAL_LOGIN),
                new NavPage('environment.connections', Vocabulary::ENTERPRISE_SSO),
                new NavPage('environment.directories', Vocabulary::DIRECTORY_SYNC),
                // "Admin Portal" joins this area when the environment console can mint a
                // portal link of its own; today a link is minted from an organization.
            ),
            new NavArea('Developers', 'code',
                new NavPage('environment.clients', Vocabulary::APPLICATIONS),
                // The resource servers apps get tokens FOR, and the scopes each owns.
                new NavPage('environment.apis', 'APIs'),
                // This environment's publishable keys, with its secret keys as the other
                // tab. The secret keys themselves are listed under AI agents › Agents — one
                // page per credential, so the two cannot disagree — and the tab leads there,
                // which is where a developer looking under "API keys" is sent.
                new NavPage('environment.keys.frontend', Vocabulary::API_KEYS),
                new NavPage('environment.webhooks', Vocabulary::WEBHOOKS),
                // Synchronous: they run INSIDE a sign-in or a token issuance and can change
                // its outcome. Webhooks, one line up, are told after the fact.
                new NavPage('environment.hooks', Vocabulary::HOOKS),
            ),
            // Where software acting on this environment is handed access and governed: the
            // agents holding its management keys, what they are waiting for a person to
            // allow, and how to point one at the environment's MCP server. Connected
            // accounts land here when they exist.
            new NavArea('AI agents', 'magic',
                new NavPage('environment.agents', Vocabulary::AGENTS),
                new NavPage('environment.approvals', Vocabulary::APPROVALS),
                new NavPage('environment.agent-connect', 'Connect'),
            ),
            new NavArea('Branding', 'palette',
                new NavPage('environment.appearance', 'Appearance'),
            ),
            new NavArea('Monitoring', 'chart',
                new NavPage('environment.audit', Vocabulary::AUDIT_LOG),
                // The audit events the APP sends about its customers — not this platform's own
                // trail, one line up — with their schemas and retention.
                new NavPage('environment.audit-logs', Vocabulary::APP_AUDIT_LOGS),
                new NavPage('environment.audit-streams', Vocabulary::LOG_STREAMS),
                new NavPage('environment.usage', 'Usage'),
            ),
            // Set up once and rarely revisited, or needed by few: the environment's own
            // staff, governance, the outbound directions, and migration.
            new NavArea('Advanced', 'sliders',
                // Roles held across the whole environment by its own people — support,
                // operations. Organizations never grant one.
                new NavPage('environment.staff', Vocabulary::ADMINS_AND_SUPPORT),
                new NavPage('environment.governance', 'Access reviews'),
                new NavPage('environment.sod-policies', 'Role conflicts'),
                new NavPage('environment.vault', 'Token vault'),
                new NavPage('environment.provisioning', Vocabulary::OUTBOUND_PROVISIONING),
                // Outbound SAML: the applications that trust THIS environment as their
                // identity provider. Enterprise SSO, under Authentication, is the inbound
                // direction — people arriving with a company account they already have.
                new NavPage('environment.sso-providers', Vocabulary::SAML_APPS),
                new NavPage('environment.legacy-login', 'Legacy login'),
            ),
            new NavArea('Settings', 'settings',
                new NavPage('environment.settings', 'Settings'),
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
     * environment console down with a RouteNotFoundException. The suite is where that
     * case is loud instead (tests/Feature/ModuleConsolePagesTest.php routes every declared
     * page on every plane it names) — in CI, not in a 500 on every page.
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
            // Connectors lands right after Developers on both rails.
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
     * Every navigation this class describes, for the code that has only a route name and
     * needs to find which area owns it.
     *
     * ONE ENTRY, and the class is now a hair from being a single method. Both the
     * organization plane and the platform section are assembled from the plugin registry
     * ({@see ConsoleServiceProvider}), so {@see ConsoleLocation} reads
     * them through `Console::nav()` and consults this only for what the registry cannot
     * answer — which is the environment plane, whose rail is declared statically because
     * it renders on tenant hosts where the registry's organization areas do not apply.
     *
     * @return list<ConsoleNav>
     */
    public function all(): array
    {
        return [
            $this->environment(),
        ];
    }
}
