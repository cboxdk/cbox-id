<?php

declare(strict_types=1);

namespace Cbox\Signals;

use Cbox\Signals\Console\DispatchEvents;
use Cbox\Signals\Contracts\EventOutbox;
use Cbox\Signals\Contracts\SignalInbox;
use Cbox\Signals\Contracts\Signals;
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
        $this->app->bind(Signals::class, fn (): HttpSignals => new HttpSignals($this->app->make(Factory::class), config()->string('signals.url', ''), config()->string('signals.token', ''), config()->float('signals.timeout', 2.0)));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DispatchEvents::class]);
        }
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'signals-migrations');
        $this->publishes([__DIR__.'/../config/signals.php' => config_path('signals.php')], 'signals-config');
    }
}
