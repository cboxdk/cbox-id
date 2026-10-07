<?php

namespace App\Providers;

use App\Http\ApiRateLimiters;
use App\Http\Controllers\Api\Discovery\AuthorizationServerMetadataController as AppAuthorizationServerMetadataController;
use App\Http\Controllers\Api\Discovery\OpenIdConfigurationController;
use App\Listeners\SuppressSandboxMail;
use App\Mcp\McpCaller;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\AppManagementScopes;
use App\Platform\AuthoritativeDnsResolver;
use App\Platform\Console\ConsoleScope;
use App\Platform\CspNonce;
use App\Platform\EnvironmentApiContext;
use App\Platform\EnvironmentKeyAuditLog;
use App\Platform\Health\ProductionConfigDoctorCheck;
use App\Platform\Health\SchedulerDoctorCheck;
use App\Platform\Health\TenancyHealthCheck;
use App\Platform\OrganizationApiContext;
use Cbox\Dns\Dns;
use Cbox\Id\Api\Http\Controllers\AuthorizationServerMetadataController;
use Cbox\Id\Api\Http\Controllers\DiscoveryController;
use Cbox\Id\Console\HealthChecks;
use Cbox\Id\Federation\Contracts\DnsResolver;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Platform\Contracts\ManagementScopes;
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
        // Discovered once per process: every door reads the same list.
        $this->app->singleton(ActionRegistry::class);

        // The scopes a management key may carry: the framework's core set plus the ones
        // this app's actions guard. The framework refuses to mint anything else.
        $this->app->singleton(ManagementScopes::class, AppManagementScopes::class);

        $this->app->singleton(DnsResolver::class, function (Application $app): DnsResolver {
            return new AuthoritativeDnsResolver($app->make(Dns::class)->authoritative());
        });

        // The authenticated account API key for the request — shared between the
        // auth middleware that sets it and the controllers that read it.
        $this->app->scoped(OrganizationApiContext::class);

        // Its environment-plane counterpart: the authenticated environment API key
        // for the request (the environment itself is host-resolved separately).
        $this->app->scoped(EnvironmentApiContext::class);

        // Who is calling the MCP server on this request — set by AuthenticateMcp, read by
        // every tool. Scoped and cleared after the request, like the key context above.
        $this->app->scoped(McpCaller::class);

        // …and what it does is recorded as ITS act: the framework services behind the
        // management API write their own audit entries, mostly with no actor at all.
        $this->app->extend(AuditLog::class, fn (AuditLog $inner): AuditLog => new EnvironmentKeyAuditLog($inner));

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

        // Discovery, plus what THIS application's `/oauth/authorize` does with `prompt`.
        // Bound over the framework's controllers so its routes keep their middleware.
        $this->app->bind(DiscoveryController::class, OpenIdConfigurationController::class);
        $this->app->bind(AuthorizationServerMetadataController::class, AppAuthorizationServerMetadataController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Contributed to `cbox-id:doctor` rather than shipped as a second health command.
        // A deployment claiming a shape it cannot serve fails silently, so the only thing
        // that makes it visible is a command someone actually runs.
        //
        // There was a console-parity check here too, failing the doctor whenever the
        // organization and environment consoles offered different pages. They do now, on
        // purpose: a customer's organization console is an admin portal and the product's
        // administration is the environment console's (see CustomerConsole).
        $checks = $this->app->make(HealthChecks::class);
        $checks->add($this->app->make(TenancyHealthCheck::class));
        $checks->add($this->app->make(SchedulerDoctorCheck::class));
        $checks->add($this->app->make(ProductionConfigDoctorCheck::class));

        // Real email never leaves a sandbox environment.
        Event::listen(MessageSending::class, SuppressSandboxMail::class);

        // The REST management API's named rate limiters. Without these registered,
        // `throttle:api-organization` would be read as a numeric limit of 0.
        ApiRateLimiters::register();
    }
}
