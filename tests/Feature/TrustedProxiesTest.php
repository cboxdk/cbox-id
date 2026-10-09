<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TRUSTED_PROXIES is read where a config cache can hold it
|--------------------------------------------------------------------------
|
| It was read with env() inside bootstrap/app.php's withMiddleware() closure, which runs when
| the HTTP kernel is resolved — before the application bootstraps. Once `config:cache` has
| run, `.env` is never loaded, so on a VM deployment that followed the docs (TRUSTED_PROXIES
| in `.env`, then `php artisan config:cache`) the value read as empty, no proxy was trusted,
| and every redirect, mailed link and absolute URL went out as `http` with the proxy's IP as
| every client's. Production on Kubernetes passes the variable in the process environment,
| which is why it worked there and only there.
|
| Measured on a production-shaped local copy: `.env` TRUSTED_PROXIES=127.0.0.1 behind a
| proxy sending X-Forwarded-Proto: https redirected to https:// without a config cache and to
| http:// with one.
*/

/**
 * Evaluate config/trustedproxy.php as a fresh process would, with TRUSTED_PROXIES set to
 * $value (null: unset).
 */
function trustedProxiesConfigFor(?string $value): mixed
{
    $previous = [$_ENV['TRUSTED_PROXIES'] ?? null, $_SERVER['TRUSTED_PROXIES'] ?? null, getenv('TRUSTED_PROXIES')];

    try {
        if ($value === null) {
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
            putenv('TRUSTED_PROXIES');
        } else {
            $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value;
            putenv("TRUSTED_PROXIES={$value}");
        }

        /** @var array{proxies: mixed} $config */
        $config = require config_path('trustedproxy.php');

        return $config['proxies'];
    } finally {
        [$env, $server, $process] = $previous;

        if ($env === null) {
            unset($_ENV['TRUSTED_PROXIES']);
        } else {
            $_ENV['TRUSTED_PROXIES'] = $env;
        }

        if ($server === null) {
            unset($_SERVER['TRUSTED_PROXIES']);
        } else {
            $_SERVER['TRUSTED_PROXIES'] = $server;
        }

        putenv($process === false ? 'TRUSTED_PROXIES' : "TRUSTED_PROXIES={$process}");
    }
}

/** What the app made of a request a proxy at 10.244.3.9 forwarded for 203.0.113.7 over https. */
function viaProxy(): string
{
    Route::get('/__trusted-proxy-probe', static fn (Request $request): string => $request->getScheme().' '.$request->ip());

    return test()
        ->withServerVariables(['REMOTE_ADDR' => '10.244.3.9'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Port' => '443'])
        ->get('http://localhost/__trusted-proxy-probe')
        ->assertOk()
        ->getContent();
}

it('reads TRUSTED_PROXIES in a config file, so config:cache keeps it', function (): void {
    expect(trustedProxiesConfigFor('10.244.0.0/16'))->toBe(['10.244.0.0/16'])
        ->and(trustedProxiesConfigFor(' 10.0.0.0/8 , 192.168.0.0/16,'))->toBe(['10.0.0.0/8', '192.168.0.0/16'])
        ->and(trustedProxiesConfigFor('*'))->toBe('*');
});

it('trusts nobody when TRUSTED_PROXIES is unset — not even on a managed platform', function (): void {
    // [] rather than null: null is Laravel's cue to trust everyone on Cloud, Forge and Vapor.
    expect(trustedProxiesConfigFor(null))->toBe([])
        ->and(trustedProxiesConfigFor(''))->toBe([]);

    config(['trustedproxy.proxies' => []]);

    expect(viaProxy())->toBe('http 10.244.3.9');
});

it('believes a listed proxy about the scheme and the client', function (): void {
    config(['trustedproxy.proxies' => ['10.244.0.0/16']]);

    expect(viaProxy())->toBe('https 203.0.113.7');
});

it('believes any peer under *', function (): void {
    config(['trustedproxy.proxies' => '*']);

    expect(viaProxy())->toBe('https 203.0.113.7');
});

it('does not believe a peer outside the list', function (): void {
    config(['trustedproxy.proxies' => ['192.168.0.0/16']]);

    expect(viaProxy())->toBe('http 10.244.3.9');
});
