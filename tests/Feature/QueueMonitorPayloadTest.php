<?php

declare(strict_types=1);

use Cbox\LaravelPostal\Webhooks\ProcessWebhook;
use Cbox\LaravelQueueMonitor\Models\JobMonitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Queues\SecretCarryingJob;

/*
|--------------------------------------------------------------------------
| The queue monitor never stores what a job carries.
|--------------------------------------------------------------------------
|
| A queued job's payload is its constructor arguments. Here that is a Postal webhook body
| (addresses, message metadata), and in whatever job is added next it may be a logout
| token or a secret. The monitor's own redaction applies only to what its API DISPLAYS —
| the stored column is the raw payload — so storage is off in config AND blanked on every
| write (App\Providers\QueueServiceProvider). This reads every monitor table back.
*/

const MONITOR_SECRET = 'lt_SECRET-logout-token-7f3a91c2';
const MONITOR_EMAIL = 'leak-canary@customer.example';

/** Everything the monitor wrote, every column of every one of its tables, as one string. */
function everythingTheMonitorStored(): string
{
    $prefix = (string) config('queue-monitor.database.table_prefix');
    $dump = '';

    foreach (['jobs', 'tags', 'scaling_events', 'cluster_events'] as $table) {
        if (Schema::hasTable($prefix.$table)) {
            $dump .= json_encode(DB::table($prefix.$table)->get()->all());
        }
    }

    return $dump;
}

/** Push onto the database queue and let one worker run it — the path production takes. */
function runOnAWorker(SecretCarryingJob $job): void
{
    dispatch($job)->onConnection('database');

    test()->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();
}

it('records jobs it is shown, and nothing they carry', function (): void {
    runOnAWorker(new SecretCarryingJob(MONITOR_SECRET, MONITOR_EMAIL));
    runOnAWorker(new SecretCarryingJob(MONITOR_SECRET, MONITOR_EMAIL, fail: true));

    // Queued only — a real job of ours whose payload is a webhook body.
    dispatch(new ProcessWebhook('default', ['event' => 'MessageSent', 'payload' => ['to' => MONITOR_EMAIL, 'token' => MONITOR_SECRET]]))
        ->onConnection('database');

    // The monitor saw all three — a check that passes because nothing was recorded
    // proves nothing.
    expect(JobMonitor::query()->count())->toBe(3)
        ->and(JobMonitor::query()->where('status', 'completed')->count())->toBe(1)
        ->and(JobMonitor::query()->where('status', 'failed')->value('exception_message'))->toBe('Relying party answered 503');

    $stored = everythingTheMonitorStored();

    expect($stored)->not->toContain(MONITOR_SECRET)
        ->and($stored)->not->toContain(MONITOR_EMAIL)
        ->and(JobMonitor::query()->whereNotNull('payload')->count())->toBe(0);
})->group('security');

it('stores no payload even when the config is flipped on', function (): void {
    // An environment variable nobody reviews, or a published config edited while
    // debugging: the guard is the write itself, not the setting.
    config(['queue-monitor.storage.store_payload' => true]);

    runOnAWorker(new SecretCarryingJob(MONITOR_SECRET, MONITOR_EMAIL));

    expect(JobMonitor::query()->count())->toBe(1)
        ->and(JobMonitor::query()->value('payload'))->toBeNull()
        ->and(everythingTheMonitorStored())->not->toContain(MONITOR_SECRET);
})->group('security');

it('ships with payload storage off, and not as an environment variable', function (): void {
    expect(config('queue-monitor.storage.store_payload'))->toBeFalse()
        ->and((string) file_get_contents(config_path('queue-monitor.php')))->toContain("'store_payload' => false,");
});
