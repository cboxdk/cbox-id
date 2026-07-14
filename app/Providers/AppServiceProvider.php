<?php

namespace App\Providers;

use App\Platform\AuthoritativeDnsResolver;
use Cbox\Dns\Resolvers\AuthoritativeResolver;
use Cbox\Dns\Resolvers\SocketResolver;
use Cbox\Id\Federation\Contracts\DnsResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Domain-ownership verification reads the challenge TXT from the domain's
        // authoritative nameservers (cboxdk/dns), not the framework's default
        // recursive resolver — so a freshly published record verifies immediately
        // instead of waiting out a recursive resolver's negative cache. Overrides
        // the framework's SystemDnsResolver binding (app providers load last).
        $this->app->singleton(DnsResolver::class, function (): DnsResolver {
            return new AuthoritativeDnsResolver(
                new AuthoritativeResolver(
                    new SocketResolver,
                    (bool) config('cbox-id.dns.allow_non_public_nameservers', false),
                ),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
