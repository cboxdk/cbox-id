<?php

declare(strict_types=1);

namespace Cbox\Signals;

use Cbox\Signals\Console\DispatchEvents;
use Cbox\Signals\Console\ReconcileManaged;
use Cbox\Signals\Contracts\CountIntervals;
use Cbox\Signals\Contracts\EventOutbox;
use Cbox\Signals\Contracts\ManagedCredential;
use Cbox\Signals\Contracts\ManagedIntegration;
use Cbox\Signals\Contracts\SignalInbox;
use Cbox\Signals\Contracts\Signals;
use Cbox\Signals\Managed\ConfiguredManagedCredential;
use Cbox\Signals\Managed\HttpManagedIntegration;
use Cbox\Signals\Support\JsonTransport;
use Cbox\Signals\Webhooks\DatabaseSignalInbox;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

class SignalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventOutbox::class, fn () => new DatabaseEventOutbox($this->app->make('db')->connection(), $this->app->make(Signals::class)));
        $this->app->bind(SignalInbox::class, fn () => new DatabaseSignalInbox($this->app->make('db')->connection()));
        $this->mergeConfigFrom(__DIR__.'/../config/signals.php', 'signals');
        $this->app->bind(CountIntervals::class, fn (): HttpCountIntervals => new HttpCountIntervals($this->app->make(Factory::class), $this->text('signals.url'), $this->text('signals.token'), $this->timeout()));
        $this->app->bind(Signals::class, fn (): HttpSignals => new HttpSignals($this->app->make(Factory::class), $this->text('signals.url'), $this->text('signals.token'), $this->timeout()));
        $this->app->bindIf(ManagedCredential::class, fn (): ConfiguredManagedCredential => new ConfiguredManagedCredential($this->text('signals.managed.token') !== '' ? $this->text('signals.managed.token') : $this->text('signals.token')));
        $this->app->bind(ManagedIntegration::class, fn (): HttpManagedIntegration => new HttpManagedIntegration(new JsonTransport($this->app->make(Factory::class), $this->text('signals.url'), $this->timeout()), $this->app->make(ManagedCredential::class)));
    }

    /** An unset environment variable reaches us as null, not as a missing key. */
    private function text(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }

    /** `SIGNALS_TIMEOUT` arrives as an int, float or numeric string; anything else is the default. */
    private function timeout(): float
    {
        $value = config('signals.timeout', 2.0);

        return is_numeric($value) && (float) $value > 0 ? (float) $value : 2.0;
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DispatchEvents::class, ReconcileManaged::class]);
        }
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'signals-migrations');
        $this->publishes([__DIR__.'/../config/signals.php' => config_path('signals.php')], 'signals-config');
    }
}
