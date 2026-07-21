<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateAccountMember;
use App\Http\Middleware\AuthenticateEnvironmentAdmin;
use App\Http\Middleware\AuthenticateOperator;
use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\EnforceImpersonationWindow;
use App\Http\Middleware\EnforcePlane;
use App\Http\Middleware\PortalSession;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequireSudo;
use App\Http\Middleware\RequireWorkspaceSudo;
use App\Listeners\RevokeTokensOnRoleChange;
use App\Platform\CurrentUser;
use App\Platform\ImpersonationAwareAuditLog;
use App\Platform\ImpersonationCallGuard;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Events\EventDelivered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request: the authenticated subject + org context.
        $this->app->scoped(CurrentUser::class);

        // Dual-attribution audit for privileged impersonation: wrap the framework
        // audit logger so EVERY recorded event (framework-emitted included) carries
        // the acting operator in its context while a marker is active. Decorating
        // the existing binding means nothing can bypass it.
        $this->app->extend(AuditLog::class, function (AuditLog $inner): AuditLog {
            return new ImpersonationAwareAuditLog($inner);
        });
    }

    public function boot(): void
    {
        // Every view can read `$me` without each component wiring it up.
        View::share('me', $this->app->make(CurrentUser::class));

        // Register the Microsoft Socialite driver (Google/GitHub are built in).
        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);

        // RBAC freshness: revoke a user's refresh tokens when their roles change, so a
        // grant/downgrade takes effect on next refresh rather than riding a stale token.
        Event::listen(EventDelivered::class, RevokeTokensOnRoleChange::class);

        // Livewire only re-runs *persistent* middleware on /livewire/update, so the
        // route-level auth guards must be registered here — in source, not via a
        // vendored edit that `composer install` would silently revert. Without this
        // the org console loses CurrentUser on every action, and a suspended
        // operator keeps full powers because AuthenticateOperator never re-checks.
        // EVERY route-level guard belongs here. A guard that is absent enforces on the
        // first page load and then silently stops: the component's actions all POST to
        // /livewire/update, where only this list re-runs. PersistentMiddlewareTest holds
        // the invariant — it walks the real route table and fails on any app middleware
        // guarding a web route that is missing here.
        Livewire::addPersistentMiddleware([
            // Ahead of Authenticate: a Livewire action on an impersonated page must
            // also self-terminate once the time-box lapses, not just full loads.
            EnforceImpersonationWindow::class,
            Authenticate::class,
            AuthenticateOperator::class,
            RedirectIfAuthenticated::class,
            // The guest Admin Portal setup screen is Livewire too — keep its
            // scoped-session guard on every /livewire/update, not just first load.
            PortalSession::class,
            // The environment control plane and the account plane are Livewire consoles:
            // without these, their actions answered unauthenticated. The snapshot checksum
            // is keyed on APP_KEY — identical on every tenant host — so a snapshot captured
            // in one tenant could be replayed against another tenant's host.
            AuthenticateEnvironmentAdmin::class,
            AuthenticateAccountMember::class,
            // Plane bulkheads and the step-up gate must hold per action too, or a retained
            // snapshot bypasses sudo permanently once confirmed.
            EnforcePlane::class,
            RequireSudo::class,
            RequireWorkspaceSudo::class,
            // Keeps the "an impersonator cannot plant persistence" property true for
            // component actions, not just full page loads.
            BlockDuringImpersonation::class,
        ]);

        // Make impersonation effectively READ-ONLY across the whole console. Route
        // middleware can't see individual Livewire actions (all POSTed to one
        // /livewire/update endpoint), so guard the `call` seam itself: while
        // impersonating, every component action is refused (403) except a tight
        // allowlist of read/navigation primitives. Deny-by-default — a new mutating
        // action is blocked with no extra wiring, so no sink can be missed.
        Livewire::listen('call', function (mixed $component, string $method): void {
            app(ImpersonationCallGuard::class)->guard($method);
        });
    }
}
