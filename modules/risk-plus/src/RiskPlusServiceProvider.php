<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus;

use App\Platform\Console\ConsoleArea;
use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsoleScope;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\RiskPlus\Contracts\GeoLocator;
use Cbox\Id\RiskPlus\Geo\NullGeoLocator;
use Cbox\Id\RiskPlus\Listeners\RecordRiskEvent;
use Cbox\Id\RiskPlus\Queries\OrganizationRiskEvents;
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
 * The Cbox ID risk-plus module. It registers adaptive-risk signals (impossible-travel,
 * new-device) into the risk pipeline and lights up a Security console to review
 * elevated events — all with zero edits to the host. Removed, the risk pipeline
 * falls back to laravel-risk's free-core signals and the console area disappears.
 *
 * Vendored in-tree under modules/, but it still registers itself the way an external
 * package would — its own provider, nav, routes, views and gates through the public
 * console-kit sockets, with no edit to app/. That is deliberate: a first-party module
 * that needed a private hook would make the extension point a fiction.
 */
class RiskPlusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

        // Console — always present. This was a licence check when the module shipped as
        // a separate paid package; vendored in-tree there is nothing to unlock.
        Console::features()->register('risk-plus', static fn (): bool => true);

        // Appended to the host's Logs area rather than a one-page "Security" area. Risk
        // events are a feed of what happened, which is what that area holds — and the
        // rail already had a "Security" entry (My account › Security) meaning something
        // entirely different, which is the worst kind of label collision.
        // Through ConsolePages, which serves BOTH planes by default. The old call went to
        // the organization rail's registry and nowhere else — so a feed of every flagged
        // sign-in in the ENVIRONMENT was readable only from a plane that has to narrow it
        // to one organization's members to be safe.
        $this->app->make(ConsolePages::class)->add(
            area: ConsoleArea::Logs,
            route: 'risk-plus.events',
            label: 'Risk events',
            feature: 'risk-plus',
            order: 40,
        );

        Console::dashboardCard(fn (): string => $this->riskCard(), 6);
    }

    /**
     * Dashboard card: how many elevated risk events in the last 24h, for the ACTING
     * ORGANIZATION's members. Empty (nothing rendered) before migrations run or when
     * the trail is clean.
     *
     * The count used to be unqualified, so it was every flagged sign-in in the
     * ENVIRONMENT. On an organization's own dashboard that is a live indicator of when
     * ANOTHER tenant is under credential stuffing — an attack signal that belongs to
     * them, published to everyone else on the deployment. The page this card links to
     * has narrowed by membership since it shipped; the card beside it did not.
     *
     * Null — an environment administrator who has not chosen an organization — renders
     * nothing rather than the environment total, because a stat with no scope on a
     * per-organization card is a different answer, not a wider one.
     */
    private function riskCard(): string
    {
        try {
            $organizationId = $this->app->make(ConsoleScope::class)->organizationId();

            if ($organizationId === null) {
                return '';
            }

            $count = $this->app->make(OrganizationRiskEvents::class)
                ->query($organizationId)
                ->where('created_at', '>=', Carbon::now()->subDay())
                ->count();
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
