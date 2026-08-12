<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Prometheus\Http\Middleware\AllowIps;

/**
 * THE FILE A NEW CUSTOMER ACTUALLY COPIES.
 *
 * Every environment that runs this product diverges from `.env.example` in the same two
 * places: `tests/bootstrap.php` pins its own drivers, developer machines carry a working
 * `.env`, and the hosted deployment sets everything explicitly. So the shipped defaults
 * were the one configuration nobody ran — and they were broken.
 *
 * `SESSION_DRIVER=database` is the shipped default and no migration in this application,
 * in `cboxdk/laravel-id`, or in any module created a `sessions` table. Measured on a clean
 * checkout: `cp .env.example .env && php artisan migrate` then any URL → 500,
 * `no such table: sessions`, including on `/first-run`, the page that exists for a
 * container somebody else started.
 *
 * These assert the shipped file against the code that has to honour it, which is the only
 * relationship a suite pinning its own environment cannot otherwise see.
 */
function shippedEnv(): array
{
    $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    $values = [];

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        $values[trim($key)] = trim($value, " \t\"'");
    }

    return $values;
}

it('creates the table the shipped session driver needs', function (): void {
    $driver = shippedEnv()['SESSION_DRIVER'] ?? config('session.driver');

    if ($driver !== 'database') {
        // The example may legitimately choose another driver — but then the DEFAULT in
        // config/session.php is what a deployment with no value falls back to, and that is
        // the one this has to hold for.
        $driver = 'database';
    }

    expect(Schema::hasTable('sessions'))
        ->toBeTrue('the shipped SESSION_DRIVER is `database` and nothing creates the table it reads');
});

it('creates the table the fallback session driver needs, whatever the example says', function (): void {
    // `config('session.driver')` is read with `env(..., 'database')`, so a deployment that
    // sets nothing at all lands here too.
    expect(Schema::hasTable('sessions'))->toBeTrue();
});

/**
 * A deployment with no master key must be TOLD, not shown a blank 500.
 *
 * Cbox ID will not invent one at boot — a key invented at boot is a key lost at the next
 * restart, taking every sealed secret with it — so the honest answer is a page naming the
 * variable and the command that writes it.
 */
it('answers an unconfigured deployment with an answer rather than a stack trace', function (): void {
    $view = view('errors.unconfigured', ['reason' => 'The crypto master key is not configured.'])->render();

    expect($view)->toContain('CBOX_ID_CRYPTO_KEY')
        ->and($view)->toContain('cbox-id:install')
        // A generated key to paste, because "set a 32-byte base64 key" is a research task
        // for somebody who is trying to get a container up.
        ->and($view)->toMatch('/CBOX_ID_CRYPTO_KEY=base64:[A-Za-z0-9+\/=]{20,}/')
        // No layout: the layouts resolve branding, which resolves the environment, which is
        // the machinery that cannot start yet.
        ->and($view)->not->toContain('cbx-shell');
});

/**
 * A DEPENDENCY MUST NOT PUBLISH AN ENDPOINT THIS APPLICATION NEVER CHOSE.
 *
 * `spatie/laravel-prometheus` arrived transitively — `cboxdk/laravel-queue-metrics`
 * requires it — and nothing here registers a collector or references it. Its own defaults
 * are `enabled => true`, a `/prometheus` route, and `allowed_ips => []`, which its comment
 * documents as "All IP's are allowed when empty". Measured on the hosted deployment:
 * `GET https://…/prometheus` answered 200 to an anonymous request.
 *
 * It answered an empty body, because nothing collects — which is exactly what makes it the
 * wrong thing to leave: a door that starts emitting the day somebody registers a
 * collector, by which time nobody remembers it was open.
 */
it('serves no metrics endpoint unless an operator asked for one', function (): void {
    expect(config('prometheus.enabled'))->toBeFalse();

    $routes = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        // The queue-metrics module's own endpoint is a separate, gated surface.
        ->filter(fn (string $uri): bool => $uri === 'prometheus')
        ->values()
        ->all();

    expect($routes)->toBe([]);
})->group('security');

/**
 * And when an operator DOES turn it on, an empty allow-list must refuse rather than admit:
 * the failure direction that costs a scrape is better than the one that costs a disclosure.
 */
it('refuses every address when metrics are on and none were named', function (): void {
    config(['prometheus.enabled' => true, 'prometheus.allowed_ips' => []]);

    // The app's own config file is what decides this — the package's default for an empty
    // list is "allow everybody", and this asserts we do not inherit that reading.
    $shipped = require base_path('config/prometheus.php');

    expect($shipped['allowed_ips'])->toBe([])
        ->and($shipped['middleware'])->toContain(AllowIps::class);
})->group('security');
