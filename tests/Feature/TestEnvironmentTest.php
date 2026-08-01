<?php

declare(strict_types=1);

use Cbox\Id\Devices\Support\DeviceConfig;

/**
 * The suite runs against its own infrastructure, whatever the host exports.
 *
 * phpunit.xml's `<env>` writes $_ENV and putenv() but not $_SERVER, and Laravel's Env
 * repository reads $_SERVER first, so an exported QUEUE_CONNECTION=redis wins over the
 * suite's own configuration. tests/bootstrap.php sets all three; this fails the moment
 * that stops being true, rather than a dozen unrelated-looking tests doing it.
 */
it('runs on its own infrastructure, not the host machine\'s', function (string $key, ?string $expected): void {
    expect(config($key))->toBe($expected);
})->with([
    'queue' => ['queue.default', 'sync'],
    'cache' => ['cache.default', 'array'],
    'session' => ['session.driver', 'array'],
    'mail' => ['mail.default', 'array'],
    // env('BROADCAST_CONNECTION') is the string "null", which env() casts to null.
    'broadcasting' => ['broadcasting.default', null],
]);

/**
 * The actual cause of the red CI, and the one no developer machine could see: queue
 * metrics ship enabled with a Redis storage driver and a listener that writes on every
 * job dispatch, so the suite required a Redis daemon nothing in this repository starts.
 * Developer machines usually have one running; the CI runner does not.
 */
it('needs no service the repository does not start', function (): void {
    expect(config('queue-metrics.enabled'))->toBeFalse();
});

/**
 * The one that survived the first fix: pinning QUEUE_CONNECTION was not enough, because
 * the devices module names its own connection and the runner exported that too. Every
 * variable carrying this application's prefixes is now stripped before the app boots, so
 * a module cannot be redirected out from under the suite either.
 */
it('lets no module name its own queue connection', function (): void {
    expect(DeviceConfig::nullableString('id-devices.queue_connection'))->toBeNull()
        ->and(DeviceConfig::nullableString('id-devices.queue'))->toBeNull();
});

/**
 * The other half of the same contract: the database connection is NOT pinned, because
 * the CI engines matrix redirects the suite to PostgreSQL and MySQL by exporting
 * DB_CONNECTION. Pinning it would put every driver back on sqlite and quietly re-create
 * the sqlite-only CI that once let a broken query and a CHAR-padding bug through.
 */
it('lets the process environment choose the database driver', function (): void {
    $bootstrap = (string) file_get_contents(base_path('tests/bootstrap.php'));

    expect($bootstrap)->not->toContain("'DB_CONNECTION'")
        ->and($bootstrap)->not->toContain("'DB_DATABASE'")
        ->and($bootstrap)->not->toContain("'DB_URL'");
});
