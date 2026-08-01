<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors;

use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Connectors\Analytics\NullConnectorAnalytics;
use Cbox\Id\Connectors\Catalog\ConnectorCatalog;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Cbox\Id\Connectors\Contracts\ConnectorAnalytics;
use Cbox\Id\Federation\Contracts\Connections as FederationConnections;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;
use Throwable;

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
        Volt::mount([__DIR__.'/../resources/views/livewire']);
        $this->loadRoutesFrom(__DIR__.'/../routes/connectors.php');

        // Console — present whenever the plugin is installed and not switched off.
        Console::features()->register('connectors', fn (): bool => $this->connectorsEnabled());

        // 60 is this module's reserved slot in the host's area ordering (see
        // App\Providers\ConsoleServiceProvider): unique orders, so the rail cannot
        // reshuffle itself depending on which modules happen to be enabled.
        Console::nav()->area('connectors', 'Connectors', 'connections', 60)
            ->page('connectors.catalog', 'Catalog', feature: 'connectors', order: 10)
            ->page('connectors.connections', 'Connections', feature: 'connectors', order: 20);

        Console::dashboardCard(fn (): string => $this->connectorsCard(), 8);
    }

    /**
     * Dashboard card: how many connectors are active for the current organization.
     * Empty (nothing rendered) before the platform's tables exist or when there is
     * no console context — never a broken dashboard.
     */
    private function connectorsCard(): string
    {
        try {
            $organizationId = Console::context()->organizationId();
            $count = $this->app->make(ConnectionsOverview::class)->activeCount($organizationId);
        } catch (Throwable) {
            return '';
        }

        return $this->app->make(ViewFactory::class)
            ->make('connectors::components.connectors-card', ['count' => $count])
            ->render();
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
