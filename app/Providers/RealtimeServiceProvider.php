<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Realtime\LiveUpdates;
use App\Platform\Realtime\RecordObserver;
use App\Platform\Realtime\RecordTopics;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;

/**
 * LIVE UPDATES: observe the records pages show, and publish what changed once the unit of
 * work that changed it is over — after the response is sent, after a job, after a command.
 *
 * Inert without Reverb: every hook asks {@see LiveUpdates::enabled()} first, so with
 * BROADCAST_CONNECTION=null (the default) nothing is buffered, queued or connected.
 */
final class RealtimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LiveUpdates::class);
    }

    public function boot(Dispatcher $events): void
    {
        foreach (array_keys(RecordTopics::MODELS) as $model) {
            if (class_exists($model)) {
                $model::observe(RecordObserver::class);
            }
        }

        $flush = function (): void {
            $this->app->make(LiveUpdates::class)->flush();
        };

        // A web request: after the response has gone out (php-fpm finishes the request
        // first), so the person who made the change never waits on it.
        $this->app->terminating($flush);

        // A queue worker handles many jobs in one process; each is its own unit of work. A
        // job that threw rolled its transactions back, and the after-commit buffer never
        // saw those; what it committed before throwing is still worth telling.
        $events->listen(JobProcessed::class, $flush);
        $events->listen(JobExceptionOccurred::class, $flush);
        $events->listen(CommandFinished::class, $flush);
    }
}
