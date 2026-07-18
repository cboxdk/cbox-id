<?php

namespace App\Providers;

use App\Listeners\SuppressSandboxMail;
use App\Platform\AccountApiContext;
use App\Platform\AuthoritativeDnsResolver;
use App\Platform\EnvironmentApiContext;
use Cbox\Dns\Dns;
use Cbox\Id\Federation\Contracts\DnsResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Domain-ownership verification reads the challenge TXT from the domain's
        // authoritative nameservers, not the framework's default recursive
        // resolver — so a freshly published record verifies immediately instead of
        // waiting out a recursive resolver's negative cache. The authoritative
        // resolver comes from cboxdk/laravel-dns's config-driven Dns front door
        // (transport, timeout, and SSRF posture live in config/dns.php). Overrides
        // the framework's SystemDnsResolver binding (app providers load last).
        $this->app->singleton(DnsResolver::class, function (Application $app): DnsResolver {
            return new AuthoritativeDnsResolver($app->make(Dns::class)->authoritative());
        });

        // The authenticated account API key for the request — shared between the
        // auth middleware that sets it and the controllers that read it.
        $this->app->scoped(AccountApiContext::class);

        // Its environment-plane counterpart: the authenticated environment API key
        // for the request (the environment itself is host-resolved separately).
        $this->app->scoped(EnvironmentApiContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Real email never leaves a sandbox environment.
        Event::listen(MessageSending::class, SuppressSandboxMail::class);
    }
}
