<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\CurrentUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
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
    }
}
