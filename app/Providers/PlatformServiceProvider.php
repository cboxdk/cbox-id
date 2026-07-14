<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateOperator;
use App\Http\Middleware\PortalSession;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Platform\CurrentUser;
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
    }

    public function boot(): void
    {
        // Every view can read `$me` without each component wiring it up.
        View::share('me', $this->app->make(CurrentUser::class));

        // Register the Microsoft Socialite driver (Google/GitHub are built in).
        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);

        // Livewire only re-runs *persistent* middleware on /livewire/update, so the
        // route-level auth guards must be registered here — in source, not via a
        // vendored edit that `composer install` would silently revert. Without this
        // the org console loses CurrentUser on every action, and a suspended
        // operator keeps full powers because AuthenticateOperator never re-checks.
        Livewire::addPersistentMiddleware([
            Authenticate::class,
            AuthenticateOperator::class,
            RedirectIfAuthenticated::class,
            // The guest Admin Portal setup screen is Livewire too — keep its
            // scoped-session guard on every /livewire/update, not just first load.
            PortalSession::class,
        ]);
    }
}
