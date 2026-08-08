<?php

declare(strict_types=1);

namespace Cbox\Id\Billing;

use App\Platform\Console\ConsoleRoutes;
use App\Platform\Console\ConsoleScope;
use Cbox\Console\Kit\Facades\Console;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;

/**
 * The Cbox ID billing module — the organization's usage rollup and its per-project plans.
 *
 * OPTIONAL, and optional in the only sense that means anything: turned off, there is no
 * route, no nav entry and no view. The page used to be a hardcoded line in the host's
 * `ConsoleServiceProvider` and a hardcoded route in `routes/web.php`, behind a capability
 * check — which made it invisible to a member who may not read billing, but present in
 * every deployment whether or not the deployment bills anybody. A self-hosted install with
 * no plans and no invoices still carried the surface, and "off" was not a state it had.
 *
 * WHY A MODULE AND NOT A COMPOSER PACKAGE. `modules/` is what this repo's optional
 * features are: analytics, compliance, connectors, devices, risk-plus and white-label all
 * live here, each with its own provider, config flag, views and gates, and each reaching
 * the app only through the public console-kit sockets. They were separate packages before
 * the open-core retirement, and the sockets they register through are the ones that
 * survived. Billing joins them rather than inventing a seventh mechanism.
 *
 * Vendored in-tree, it still registers itself the way an external package would — no edit
 * to `app/` anywhere in this file's reach. That is deliberate: a first-party module that
 * needed a private hook would make the extension point a fiction.
 *
 * THE TWO GATES ARE DIFFERENT QUESTIONS and both are asked. `billing.enabled` is whether
 * this DEPLOYMENT bills at all; `canReadBilling()` is whether THIS PERSON may see the
 * figures. Collapsing them would mean either a deployment that cannot switch the feature
 * off, or a member who sees a page of somebody else's money.
 */
class BillingServiceProvider extends ServiceProvider
{
    /**
     * The nav order the page held while it was hardcoded into the Identity platform area.
     *
     * Kept exactly, because the area's orders are unique across modules by contract (see
     * the host's ConsoleServiceProvider): 70 was Billing's and nothing else claims it, so
     * moving the registration out of the host must not move the entry in the rail.
     */
    private const NAV_ORDER = 70;

    /**
     * No `register()` and no `mergeConfigFrom()`. The switch lives at `config/billing.php`
     * in the host, which is where every other module under `modules/` keeps its config —
     * `id-devices.php`, `whitelabel.php`, `compliance.php`, `connectors.php`,
     * `risk-plus.php`, `id-analytics.php`. A config file the deployment can see and edit
     * is the point of a switch; one buried in the module would also put `env()` outside
     * `config/`, which returns null the moment config is cached.
     */
    public function boot(): void
    {
        // Views and the Volt component mount unconditionally, exactly as the other
        // modules do. A view path that resolves and stays unreached costs nothing, and it
        // means enabling the feature later is a config change rather than a deploy.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'billing');
        Volt::mount([__DIR__.'/../resources/views/livewire']);

        $this->registerFeature();
        $this->registerNav();
        $this->registerRoutes();
    }

    /**
     * TWO features, because the module answers two questions that must not collapse into
     * one — and collapsing them is exactly what a single flag did.
     *
     *  - `billing` — does this DEPLOYMENT bill at all? It gates the ROUTE, so a deployment
     *    with the module off has no `/billing` to reach: absent, not forbidden, which is
     *    what makes the feature genuinely optional rather than merely permissioned.
     *
     *  - `organization.billing` — may THIS PERSON see the figures? It gates the NAV entry,
     *    so a Developer — a technical role with no claim on the money — is not offered a
     *    link to a page that will turn them away.
     *
     * THE ROUTE DELIBERATELY DOES NOT ASK THE SECOND. A member who may not read billing is
     * REDIRECTED to Projects by the page's own `mount()`, and that redirect is the product's
     * choice: billing is not a secret surface, everyone in the organization knows it exists,
     * and a stale bookmark should land somewhere useful rather than on a lie. Gating the
     * route on the capability turned that redirect into a 404 — the outer gate wins, and the
     * page's own answer never runs.
     *
     * `organization.billing` KEEPS ITS OLD NAME rather than being renamed to something
     * module-shaped. It is a published extension point: the host's layout and any plugin
     * that wants to sit beside this page name it, and a module that renames its own feature
     * on the way out of the host silently unhooks every one of them.
     *
     * CLOSURES, so they are evaluated per render: the answer depends on who is signed in
     * and which organization they are acting for, neither of which is known at boot.
     */
    private function registerFeature(): void
    {
        $features = Console::features();

        $features->register('billing', static fn (): bool => config('billing.enabled') === true);

        $features->register('organization.billing', static function (): bool {
            if (config('billing.enabled') !== true) {
                return false;
            }

            return app(ConsoleScope::class)->capabilities()?->canReadBilling() === true;
        });
    }

    private function registerNav(): void
    {
        // INTO THE HOST'S AREA, not an area of its own. Billing is one page about the
        // identity platform an organization owns, and it belongs beside that platform's
        // projects and keys — a rail entry of its own for a single page would be a second
        // place to look for the same subject. `area()` on the registry is idempotent: it
        // returns the existing area when the host has already declared it.
        Console::nav()->area('identity-platform', 'Identity platform', 'layers', 15)
            ->page('billing', 'Billing', feature: 'organization.billing', order: self::NAV_ORDER);
    }

    private function registerRoutes(): void
    {
        // ORGANIZATION PLANE ONLY. Billing is an account-level concern — what the customer
        // owes for the products they own — and an environment administrator is a
        // control-plane identity acting inside one tenant, with no bill of their own to
        // read. Serving it on that plane would render a permanently empty page.
        ConsoleRoutes::organizationPage(
            feature: 'billing',
            uri: '/billing',
            component: 'billing',
            name: 'billing',
        );
    }
}
