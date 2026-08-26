<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors;

use App\Http\Props\Console\DashboardCardProps;
use App\Platform\Console\ConsoleArea;
use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\DashboardCards;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Connectors\Analytics\NullConnectorAnalytics;
use Cbox\Id\Connectors\Catalog\ConnectorCatalog;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Cbox\Id\Connectors\Contracts\ConnectorAnalytics;
use Cbox\Id\Federation\Contracts\Connections as FederationConnections;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * The Cbox ID connectors module. It plugs a unified connector catalog and a per-organization connections
 * console OVER the platform's existing Provisioning, Webhook, Directory and
 * Federation module contracts — making zero edits to the host and adding no schema.
 * Removed, the extra "Connectors" console area simply disappears; the host's own
 * scattered pages for each module are untouched and keep working.
 *
 * Delivery-health analytics is referenced ONLY behind {@see ConnectorAnalytics},
 * whose default is inert — so the framework carries no column-store dependency and
 * the SaaS host can bind a real backend later with no UI change.
 *
 * Vendored in-tree under modules/, but it still registers itself the way an external
 * package would — its own provider, nav, routes, views and gates through the public
 * console-kit sockets, with no edit to app/. That is deliberate: a first-party module
 * that needed a private hook would make the extension point a fiction.
 */
class ConnectorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Delivery-health analytics is the host's to provide; default to a backend
        // that reports nothing, so the console renders without any analytics store.
        $this->app->bindIf(ConnectorAnalytics::class, NullConnectorAnalytics::class);

        $this->app->singleton(ConnectorCatalog::class);

        $this->app->bind(ConnectionsOverview::class, fn (Application $app): ConnectionsOverview => new ConnectionsOverview(
            $app->make(ProvisioningConnections::class),
            $app->make(WebhookRegistry::class),
            $app->make(FederationConnections::class),
            $app->make(ConnectorAnalytics::class),
            $this->webhookEventTypes(),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'connectors');
        $this->loadRoutesFrom(__DIR__.'/../routes/connectors.php');

        // Console — present whenever the plugin is installed and not switched off.
        Console::features()->register('connectors', fn (): bool => $this->connectorsEnabled());

        // The area's label, icon and its reserved slot in the rail's ordering are held on
        // ConsoleArea rather than passed from here, so the two rails place it identically
        // and no module can restyle an area it merely contributes to.
        //
        // Through ConsolePages, which serves BOTH planes by default. Connectors is the
        // one module that introduces an area of its own, and it existed on the
        // organization rail alone — the environment console had no Connectors entry at
        // all, for the plane that can see every organization's connectors at once.
        $pages = $this->app->make(ConsolePages::class);

        $pages->add(
            area: ConsoleArea::Connectors,
            route: 'connectors.catalog',
            label: 'Catalog',
            feature: 'connectors',
            order: 10,
        );

        $pages->add(
            area: ConsoleArea::Connectors,
            route: 'connectors.connections',
            label: 'Connections',
            feature: 'connectors',
            order: 20,
        );

        $this->app->make(DashboardCards::class)->add(fn (): ?DashboardCardProps => $this->connectorsCard(), 8);
    }

    /**
     * Dashboard card: how many connectors are active for the acting organization.
     * Empty (nothing rendered) before the platform's tables exist or when no
     * organization is resolved — never a broken dashboard.
     *
     * Through {@see ConsoleScope}, not the console-kit context. That context answers
     * `CurrentUser::organizationId()`, which returns null for a member whose membership
     * has gone — and `activeCount(null)` is the ENVIRONMENT-wide overview. Those are
     * the two readings of null that the scope throws on precisely to keep apart: null
     * from the scope can only mean "an environment administrator has not chosen yet",
     * never "this person's organization could not be resolved". The connectors pages
     * document not making this mistake; the module's own provider was making it.
     */
    /**
     * DATA, NOT MARKUP — the console draws the card. A throw is caught by the registry and
     * the card is simply absent.
     */
    private function connectorsCard(): ?DashboardCardProps
    {
        $organizationId = $this->app->make(ConsoleScope::class)->organizationId();

        if ($organizationId === null) {
            return null;
        }

        $count = $this->app->make(ConnectionsOverview::class)->activeCount($organizationId);

        return new DashboardCardProps(
            key: 'connectors.active',
            label: 'Active connectors',
            value: number_format($count),
            caption: null,
            icon: 'connections',
            tone: 'info',
            linkLabel: 'View connections',
            linkHref: route('connectors.connections'),
        );
    }

    private function connectorsEnabled(): bool
    {
        $value = config('connectors.enabled', true);

        return is_bool($value) ? $value : (bool) $value;
    }

    /**
     * @return list<string>
     */
    private function webhookEventTypes(): array
    {
        $value = config('connectors.webhook_event_types', []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
