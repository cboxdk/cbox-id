<?php

declare(strict_types=1);
use Pdo\Pgsql;

/*
|--------------------------------------------------------------------------
| The PostgreSQL connection is kept between requests, and a query is one round trip
|--------------------------------------------------------------------------
|
| Measured on production (2026-11-12, php-fpm, PostgreSQL 18 in the same namespace): a new
| connection costs 19-23 ms before the first query (SCRAM-SHA-256), `set search_path` two more
| round trips, and every query three (prepare, execute, deallocate) at 1.2-1.5 ms each from a
| pod on another node. A request that asked one question paid 35-45 ms for it.
|
| These tests hold the three settings that remove that: the connection is persistent, a query
| goes out as one parameterised message (the parameters still travel apart from the SQL, so
| nothing is escaped into the statement), and the search path is part of the connection's
| start-up packet instead of a statement on every request.
*/

/**
 * config/database.php evaluated again under $env, as a pod with those variables would read it.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function pgsqlConnectionUnder(array $env): array
{
    $before = [];

    foreach ($env as $name => $value) {
        $before[$name] = getenv($name);
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    try {
        /** @var array{connections: array{pgsql: array<string, mixed>}} $config */
        $config = require config_path('database.php');

        return $config['connections']['pgsql'];
    } finally {
        foreach ($before as $name => $value) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

it('keeps the PostgreSQL connection between requests', function (): void {
    $options = pgsqlConnectionUnder([])['options'];

    expect($options)->toBeArray()
        ->and($options[PDO::ATTR_PERSISTENT] ?? null)->toBeTrue();
});

it('sends a query as one parameterised message instead of prepare, execute and deallocate', function (): void {
    $options = pgsqlConnectionUnder([])['options'];

    expect($options[Pgsql::ATTR_DISABLE_PREPARES] ?? null)->toBeTrue()
        // The parameters must keep travelling apart from the SQL.
        ->and($options[PDO::ATTR_EMULATE_PREPARES] ?? false)->toBeFalse();
});

it('states the search path when the connection opens, not with a statement on every request', function (): void {
    $connection = pgsqlConnectionUnder([]);

    expect($connection)->not->toHaveKey('search_path')
        ->and($connection)->not->toHaveKey('schema')
        ->and($connection['server_options'] ?? [])->toBe(['search_path' => 'public']);
});

it('lets a deployment turn either setting off', function (): void {
    $options = pgsqlConnectionUnder(['DB_PERSISTENT' => 'false', 'DB_DISABLE_PREPARES' => 'false'])['options'];

    expect($options)->not->toHaveKey(PDO::ATTR_PERSISTENT)
        ->and($options)->not->toHaveKey(Pgsql::ATTR_DISABLE_PREPARES);
});
