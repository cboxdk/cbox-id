<?php

declare(strict_types=1);

namespace App\Providers;

use App\ServiceConnections\ConnectionEvents;
use App\ServiceConnections\Contracts\Connections;
use App\ServiceConnections\Contracts\ReadsConnectionEvents;
use App\ServiceConnections\OrganizationConnections;
use Illuminate\Support\ServiceProvider;

class ServiceConnectionsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [Connections::class => OrganizationConnections::class, ReadsConnectionEvents::class => ConnectionEvents::class];

    public function boot(): void
    {
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
