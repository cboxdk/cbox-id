<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus;

use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\RiskPlus\Contracts\GeoLocator;
use Cbox\Id\RiskPlus\Geo\NullGeoLocator;
use Cbox\Id\RiskPlus\Listeners\RecordRiskEvent;
use Cbox\Id\RiskPlus\Models\RiskEvent;
use Cbox\Id\RiskPlus\Signals\ImpossibleTravelSignal;
use Cbox\Id\RiskPlus\Signals\NewDeviceSignal;
use Cbox\Id\RiskPlus\Support\SubjectKey;
use Cbox\Risk\Events\RiskAssessed;
use Cbox\Risk\Facades\Risk;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;
use Throwable;

/**
 * The Cbox ID risk-plus plugin. Installed alongside laravel-risk and a
 * console-kit host, it registers premium adaptive-risk signals (impossible-travel,
 * new-device) into the risk pipeline and lights up a Security console to review
 * elevated events — all with zero edits to the host. Removed, the risk pipeline
 * falls back to laravel-risk's free-core signals and the console area disappears.
 */
class RiskPlusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/risk-plus.php', 'risk-plus');

        // Geo source is the operator's to provide; default to a locator that
        // locates nothing so impossible-travel is safely inert until wired.
        $this->app->bindIf(GeoLocator::class, NullGeoLocator::class);

        $this->app->singleton(SubjectKey::class, static function (Application $app): SubjectKey {
            $key = config('app.key');

            return new SubjectKey(is_string($key) && $key !== '' ? $key : 'cbox-riskplus');
        });

        $this->app->bind(ImpossibleTravelSignal::class, fn (Application $app): ImpossibleTravelSignal => new ImpossibleTravelSignal(
            $app->make(GeoLocator::class),
            $app->make(SubjectKey::class),
            $app->make(Cache::class),
            $this->configFloat('risk-plus.impossible_travel.max_speed_kmh', 900),
            $this->configFloat('risk-plus.impossible_travel.min_distance_km', 50),
            $this->configFloat('risk-plus.impossible_travel.points', 60),
            $this->configInt('risk-plus.impossible_travel.ttl', 604800),
        ));

        $this->app->bind(NewDeviceSignal::class, fn (Application $app): NewDeviceSignal => new NewDeviceSignal(
            $app->make(SubjectKey::class),
            $app->make(Cache::class),
            $this->configFloat('risk-plus.new_device.points', 35),
            $this->configInt('risk-plus.new_device.max_devices', 20),
            $this->configInt('risk-plus.new_device.ttl', 7776000),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'risk-plus');
        Volt::mount([__DIR__.'/../resources/views/livewire']);
        $this->loadRoutesFrom(__DIR__.'/../routes/risk-plus.php');

        // The hook that makes this a plugin: contribute premium signals to the risk
        // pipeline from here — no edit to the host's config('risk.signals').
        Risk::signals()->register(ImpossibleTravelSignal::class);
        Risk::signals()->register(NewDeviceSignal::class);

        // Record elevated assessments for the console to review.
        $this->app->make(Dispatcher::class)->listen(RiskAssessed::class, RecordRiskEvent::class);

        // Console — present whenever the plugin is installed (licensed).
        Console::features()->register('risk-plus', static fn (): bool => true);

        Console::nav()->area('security', 'Security', 'shield', 60)
            ->page('risk-plus.events', 'Risk events', feature: 'risk-plus', order: 10);

        Console::dashboardCard(fn (): string => $this->riskCard(), 6);
    }

    /**
     * Dashboard card: how many elevated risk events in the last 24h. Empty (nothing
     * rendered) before migrations run or when the trail is clean.
     */
    private function riskCard(): string
    {
        try {
            $count = RiskEvent::query()->where('created_at', '>=', Carbon::now()->subDay())->count();
        } catch (Throwable) {
            return '';
        }

        return $this->app->make(ViewFactory::class)
            ->make('risk-plus::components.risk-card', ['count' => $count])
            ->render();
    }

    private function configFloat(string $key, float $default): float
    {
        $value = config($key, $default);

        return is_int($value) || is_float($value) ? (float) $value : (is_numeric($value) ? (float) $value : $default);
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }
}
