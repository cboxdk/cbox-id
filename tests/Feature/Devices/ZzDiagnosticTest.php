<?php

declare(strict_types=1);

use Cbox\Id\Devices\Contracts\PushDispatcher;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * TEMPORARY. Prints what the runner actually resolves, because the failure does not
 * reproduce on any developer machine and the collapsed Pest trace does not name the
 * frame that reaches Redis. Delete once the cause is fixed.
 */
it('reports the resolved delivery environment', function (): void {
    $report = [
        'queue.default' => config('queue.default'),
        'queue class' => $this->app['queue']->connection()::class,
        'cache.default' => config('cache.default'),
        'cache store' => $this->app['cache']->store()->getStore()::class,
        'bus cache repo' => $this->app->make(Repository::class)->getStore()::class,
        'id-devices.queue_connection' => var_export(config('id-devices.queue_connection'), true),
        'id-devices.queue' => var_export(config('id-devices.queue'), true),
        'id-devices.enabled' => var_export(config('id-devices.enabled'), true),
    ];

    foreach (['QUEUE_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER', 'REDIS_HOST', 'REDIS_CLIENT'] as $name) {
        $report['$_SERVER '.$name] = var_export($_SERVER[$name] ?? null, true);
        $report['getenv '.$name] = var_export(getenv($name), true);
    }

    foreach (array_keys($_SERVER) as $name) {
        if (is_string($name) && (str_starts_with($name, 'CBOX_ID_') || str_starts_with($name, 'ID_'))) {
            $report['inherited '.$name] = var_export($_SERVER[$name], true);
        }
    }

    fwrite(STDERR, "\n===== DELIVERY ENVIRONMENT =====\n");

    foreach ($report as $key => $value) {
        fwrite(STDERR, str_pad($key, 34).' = '.(is_string($value) ? $value : var_export($value, true))."\n");
    }

    try {
        app(PushDispatcher::class)->dispatch('user_diag', NotificationKind::Approval, new PushPayload(title: 'T', body: 'B'));
        fwrite(STDERR, "dispatch: no exception\n");
    } catch (Throwable $e) {
        fwrite(STDERR, 'dispatch threw '.$e::class.': '.$e->getMessage()."\n");

        foreach (array_slice($e->getTrace(), 0, 22) as $i => $frame) {
            fwrite(STDERR, sprintf(
                "#%-2d %s%s%s()  %s:%s\n",
                $i,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? '',
                basename($frame['file'] ?? '-'),
                $frame['line'] ?? '-',
            ));
        }
    }

    fwrite(STDERR, "================================\n\n");

    expect(true)->toBeTrue();
});
