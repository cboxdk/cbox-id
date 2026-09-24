<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Health\QueueWorkersDoctorCheck;
use App\Platform\Queues\CacheManagerHeartbeat;
use App\Platform\Queues\Contracts\ManagerHeartbeat;
use App\Platform\Queues\Contracts\QueueHealth;
use App\Platform\Queues\Listeners\RecordManagerHeartbeat;
use App\Platform\Queues\LiveQueueHealth;
use App\Platform\Queues\QueueMonitorAccess;
use Cbox\Id\Console\HealthChecks;
use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStarted;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueMonitor\LaravelQueueMonitor;
use Cbox\LaravelQueueMonitor\Models\JobMonitor;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The queue workers: who runs them (`queue:autoscale`), how anyone can tell they are
 * running (the heartbeat and `/health/ready`), and who may look inside (the monitor).
 */
class QueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The DEFAULT store, resolved per call rather than captured at boot: the manager
        // and the web tier must meet in the same place, and that place is whatever the
        // deployment configured as its cache.
        $this->app->bind(ManagerHeartbeat::class, static fn (Application $app): CacheManagerHeartbeat => new CacheManagerHeartbeat(
            $app->make(Repository::class),
        ));

        $this->app->bind(QueueHealth::class, LiveQueueHealth::class);
    }

    public function boot(): void
    {
        Event::listen([AutoscaleManagerStarted::class, ScalingDecisionMade::class, WorkersScaled::class], RecordManagerHeartbeat::class);

        $this->app->make(HealthChecks::class)->add($this->app->make(QueueWorkersDoctorCheck::class));

        // The monitor's own authorization callback — the second lock behind the route
        // middleware. See QueueMonitorAccess for why it asks about the host as well.
        LaravelQueueMonitor::auth(static fn (Request $request): bool => app(QueueMonitorAccess::class)->allows($request));

        /*
         * NO JOB PAYLOAD IS EVER STORED — enforced here, not only switched off in config.
         *
         * A serialized job is its constructor arguments: a webhook's delivery id is
         * harmless, but a Postal delivery carries the raw webhook body (addresses,
         * message metadata), and any job somebody adds tomorrow may carry a token, an
         * email address or a secret. The package's redaction applies only to what its
         * API and dashboard DISPLAY; the stored column is the raw payload, kept for
         * replay. So `storage.store_payload` is false — and because a job can override
         * that per class (`shouldStorePayload()`), and a config value can be flipped by
         * an environment variable nobody reviews, the column is also blanked on every
         * write. Replay goes with it, deliberately: Laravel's own `failed_jobs` keeps
         * what `queue:retry` needs, behind the database's access control rather than a
         * web page's.
         */
        JobMonitor::saving(static function (JobMonitor $record): void {
            $record->setAttribute('payload', null);
        });
    }
}
