<?php

declare(strict_types=1);

namespace App\Providers;

use App\ServiceConnections\Contracts\Connections;
use App\ServiceConnections\OrganizationConnections;
use Illuminate\Support\ServiceProvider;

class ServiceConnectionsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [Connections::class => OrganizationConnections::class];
}
