<?php

namespace App\Providers;

use App\Http\ApiRateLimiters;
use App\Listeners\SuppressSandboxMail;
use App\Platform\OrganizationApiContext;
use App\Platform\AuthoritativeDnsResolver;
use App\Platform\Console\ConsoleScope;
use App\Platform\CspNonce;
use App\Platform\EnvironmentApiContext;
use App\Platform\Health\ConsoleParityHealthCheck;
use App\Platform\Health\TenancyHealthCheck;
use Cbox\Dns\Dns;
use Cbox\Id\Console\HealthChecks;
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
        $this->app->scoped(OrganizationApiContext::class);

        // Its environment-plane counterpart: the authenticated environment API key
        // for the request (the environment itself is host-resolved separately).
        $this->app->scoped(EnvironmentApiContext::class);

        // One CSP nonce per request. `scoped` and not `singleton`: on a long-lived worker
        // a singleton would hand the same value to every request the process ever serves,
        // which is a nonce in name only — anyone who saw one page could predict the value
        // guarding the next.
        $this->app->scoped(CspNonce::class);

        // The console's one answer to "who is acting, on which organization, and what
        // may they do". Scoped, not singleton: the environment plane picks an
        // organization per request, and a singleton would carry one administrator's
        // choice into the next request on a long-lived worker.
        $this->app->scoped(ConsoleScope::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Contributed to `cbox-id:doctor` rather than shipped as a second health command.
        // Both findings these catch failed silently — a deployment claiming a shape it
        // cannot serve, and two console planes grown apart — so the only thing that makes
        // them visible is a command someone actually runs.
        $checks = $this->app->make(HealthChecks::class);
        $checks->add($this->app->make(TenancyHealthCheck::class));
        $checks->add($this->app->make(ConsoleParityHealthCheck::class));

        // Real email never leaves a sandbox environment.
        Event::listen(MessageSending::class, SuppressSandboxMail::class);

        // The REST management API's named rate limiters. Without these registered,
        // `throttle:api-organization` would be read as a numeric limit of 0.
        ApiRateLimiters::register();
    }
}
