<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel;

use App\Platform\Console\ConsoleArea;
use App\Platform\Console\ConsolePages;
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
 * The Cbox ID white-label module. It OVERRIDES
 * console-kit's null {@see BrandingResolver} with a tenant-aware one, so the shell and
 * hosted auth theme themselves from each environment's/organization's BrandProfile —
 * palette, logo, favicon, app name, custom domain and email sender — with zero host
 * edits. Removed, the resolver falls back to the null default and the shell returns to
 * its static Cbox branding.
 *
 * Vendored in-tree under modules/, but it still registers itself the way an external
 * package would — its own provider, nav, routes, views and gates through the public
 * console-kit sockets, with no edit to app/. That is deliberate: a first-party module
 * that needed a private hook would make the extension point a fiction.
 */
class WhitelabelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The profile store — swappable, but Eloquent by default.
        $this->app->bindIf(BrandProfiles::class, DatabaseBrandProfiles::class);

        // Turn the feature on: replace the inert null resolver with the tenant one.
        $this->app->bind(BrandingResolver::class, TenantBrandingResolver::class);

        // Assets default to a local disk so the framework needs no S3 dependency.
        //
        // Built from config rather than taken from `Storage::disk()`, and that is not a
        // preference. Telemetry's filesystem instrumentation — on by default — replaces
        // the manager, so `Storage::disk()` hands back an `InstrumentedFilesystem`. That
        // decorator implements `Filesystem` and forwards `url()` through `__call`, but it
        // does not declare `Cloud`, which is what this store's constructor requires. The
        // result was a TypeError every time the branding page resolved its asset store:
        // the whole white-label surface was dead in any deployment with telemetry on,
        // which is the default. Found by a test written for something else entirely.
        //
        // What that costs is three storage operations' worth of instrumentation on brand
        // asset writes. The alternative — widening the store to `Filesystem` — would mean
        // calling `url()` on a type that does not declare it.
        $this->app->bindIf(BrandAssetStore::class, static fn (Application $app): BrandAssetStore => new LocalBrandAssetStore(
            Storage::build(self::diskConfig($app, self::configString($app, 'whitelabel.assets.disk', 'public'))),
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

        // Console — always present. This was a licence check when the module shipped as
        // a separate paid package; vendored in-tree there is nothing to unlock.
        Console::features()->register('whitelabel', static fn (): bool => true);

        // Through ConsolePages, which serves BOTH planes by default. The old call went to
        // the organization rail's registry and nowhere else, so the environment default
        // every organization inherits — the row this module's schema is built around —
        // had no editor anywhere in the console.
        //
        // The area's icon is no longer passed from here either. This call used to hand
        // 'palette' to the host's Settings area, and the registry applies a passed icon
        // as an override: installing the branding module restyled the console's Settings
        // rail entry for every page under it.
        $this->app->make(ConsolePages::class)->add(
            area: ConsoleArea::Settings,
            route: 'whitelabel.branding',
            label: 'Branding',
            feature: 'whitelabel',
            order: 10,
        );

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

    /**
     * The raw disk configuration, so a disk can be built without going through the
     * (possibly decorated) manager.
     *
     * @return array<string, mixed>
     */
    private static function diskConfig(Application $app, string $disk): array
    {
        $config = $app->make('config')->get('filesystems.disks.'.$disk);

        if (! is_array($config)) {
            return ['driver' => 'local', 'root' => storage_path('app/public')];
        }

        /** @var array<string, mixed> $typed */
        $typed = array_filter($config, static fn (mixed $value, mixed $key): bool => is_string($key), ARRAY_FILTER_USE_BOTH);

        return $typed;
    }

    private static function configString(Application $app, string $key, string $default): string
    {
        $value = $app->make('config')->get($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
