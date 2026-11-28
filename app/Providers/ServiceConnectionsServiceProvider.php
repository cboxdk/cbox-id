<?php

declare(strict_types=1);

namespace App\Providers;

use App\ServiceConnections\BillingConnectionEligibility;
use App\ServiceConnections\ConnectionEvents;
use App\ServiceConnections\Contracts\ChecksConnectionEligibility;
use App\ServiceConnections\Contracts\Connections;
use App\ServiceConnections\Contracts\ReadsConnectionEvents;
use App\ServiceConnections\OrganizationConnections;
use Illuminate\Support\ServiceProvider;

class ServiceConnectionsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [Connections::class => OrganizationConnections::class, ReadsConnectionEvents::class => ConnectionEvents::class,
        ChecksConnectionEligibility::class => BillingConnectionEligibility::class];

    public function boot(): void
    {
        // Assets uses one audience for MCP and service leases. Keep legacy lease scopes
        // available during migration, but reserved against self-registration. Ordinary
        // API scopes are not globally reserved: webhooks:write also belongs to ID.
        $legacyAssetsScopes = ['assets:consumer:read', 'assets:consumer:write'];
        $assetsScopes = [...array_filter(config()->array('service-connections.services.cbox_assets.scopes'), is_string(...)), ...$legacyAssetsScopes];
        config(['cbox-id.oauth.reserved_scopes' => array_values(array_unique([
            ...array_filter(config()->array('cbox-id.oauth.reserved_scopes', []), is_string(...)), ...$legacyAssetsScopes,
            'id:service-connections:write', 'service_connections.manage',
            'id:service-connection-provisions:write',
        ]))]);
        $resources = config()->array('cbox-id.oauth.protected_resources', []);
        foreach ($resources as $key => $resource) {
            if (! is_array($resource) || ($resource['identifier'] ?? null) !== config()->string('service-connections.services.cbox_assets.audience')) {
                continue;
            }
            $scopes = $resource['scopes'] ?? [];
            $scopes = is_string($scopes) ? explode(' ', $scopes) : $scopes;
            $resource['scopes'] = array_values(array_unique([...(is_array($scopes) ? array_filter($scopes, is_string(...)) : []), ...$assetsScopes]));
            $resources[$key] = $resource;
        }
        config(['cbox-id.oauth.protected_resources' => $resources]);

        // The registered service may introspect only its own API's tokens.
        $grants = config()->array('cbox-id.oauth.introspection.resource_servers', []);
        foreach (config()->array('service-connections.services') as $definition) {
            if (! is_array($definition) || ! is_string($definition['client_id'] ?? null) || $definition['client_id'] === '' || ! is_string($definition['audience'] ?? null)) {
                continue;
            }
            $previous = $grants[$definition['client_id']] ?? [];
            $grants[$definition['client_id']] = array_values(array_unique([...(is_array($previous) ? array_filter($previous, is_string(...)) : []), $definition['audience']]));
        }
        config(['cbox-id.oauth.introspection.resource_servers' => $grants]);
    }
}
