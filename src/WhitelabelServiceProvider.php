<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel;

use Cbox\Console\Kit\Contracts\BrandingResolver;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Whitelabel\Assets\BrandAssetStore;
use Cbox\Id\Whitelabel\Assets\LocalBrandAssetStore;
use Cbox\Id\Whitelabel\Branding\TenantBrandingResolver;
use Cbox\Id\Whitelabel\BrandProfiles\DatabaseBrandProfiles;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\CustomDomain\ManageCustomDomain;
use Cbox\Ssrf\Contracts\UrlGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;
use Throwable;

/**
 * The Cbox ID white-label plugin. Installed alongside a console-kit host, it OVERRIDES
 * console-kit's null {@see BrandingResolver} with a tenant-aware one, so the shell and
 * hosted auth theme themselves from each environment's/organization's BrandProfile —
 * palette, logo, favicon, app name, custom domain and email sender — with zero host
 * edits. Removed, the resolver falls back to the null default and the shell returns to
 * its static Cbox branding.
 */
final class WhitelabelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/whitelabel.php', 'whitelabel');

        // The profile store — swappable, but Eloquent by default.
        $this->app->bindIf(BrandProfiles::class, DatabaseBrandProfiles::class);

        // Turn the feature on: replace the inert null resolver with the tenant one.
        $this->app->bind(BrandingResolver::class, TenantBrandingResolver::class);

        // Assets default to a local disk so the framework needs no S3 dependency.
        $this->app->bindIf(BrandAssetStore::class, static fn (Application $app): BrandAssetStore => new LocalBrandAssetStore(
            Storage::disk(self::configString($app, 'whitelabel.assets.disk', 'public')),
            $app->make(EnvironmentContext::class),
            self::configString($app, 'whitelabel.assets.path', 'brand'),
        ));

        $this->app->bind(ManageCustomDomain::class, static fn (Application $app): ManageCustomDomain => new ManageCustomDomain(
            $app->make(EnvironmentContext::class),
            $app->make(UrlGuard::class),
            (bool) $app->make('config')->get('whitelabel.custom_domain.verify_host', true),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'whitelabel');
        Volt::mount([__DIR__.'/../resources/views/livewire']);
        $this->loadRoutesFrom(__DIR__.'/../routes/whitelabel.php');

        // Console — present whenever the plugin is installed (licensed).
        Console::features()->register('whitelabel', static fn (): bool => true);

        Console::nav()->area('settings', 'Settings', 'palette', 80)
            ->page('whitelabel.branding', 'Branding', feature: 'whitelabel', order: 10);

        Console::dashboardCard(fn (): string => $this->brandCard(), 8);
    }

    /**
     * Dashboard card summarizing the current tenant's branding. Empty (nothing
     * rendered) before migrations run or when branding cannot be resolved.
     */
    private function brandCard(): string
    {
        try {
            $branding = Console::branding();
        } catch (Throwable) {
            return '';
        }

        return $this->app->make(ViewFactory::class)
            ->make('whitelabel::components.whitelabel.brand-card', ['branding' => $branding])
            ->render();
    }

    private static function configString(Application $app, string $key, string $default): string
    {
        $value = $app->make('config')->get($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
