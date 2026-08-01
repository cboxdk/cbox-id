<?php

declare(strict_types=1);

/**
 * The suite runs against its own infrastructure, whatever the host exports.
 *
 * phpunit.xml's `<env>` writes $_ENV and putenv() but not $_SERVER, and Laravel's Env
 * repository reads $_SERVER first — so a machine exporting QUEUE_CONNECTION=redis used
 * to win, and this repository's self-hosted CI runner does exactly that. Every job went
 * at a Redis nobody started ("RedisException: Connection refused") on commits that were
 * green on every developer machine. tests/bootstrap.php now sets all three; this fails
 * the moment that stops being true, rather than 40 unrelated-looking tests doing it.
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
