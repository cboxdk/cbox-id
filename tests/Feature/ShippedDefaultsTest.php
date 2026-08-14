<?php

declare(strict_types=1);

use App\Http\Middleware\AllowNamedIpsOnly;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 * And when an operator DOES turn it on, an empty allow-list must refuse rather than admit.
 *
 * THIS USED TO ASSERT THE CONFIG FILE'S OWN LITERALS BACK AT ITSELF — that `allowed_ips`
 * was `[]` when no env var is set, and that a middleware class was listed. Both were
 * trivially true, and neither said anything about what a request gets. The control it
 * claimed did not exist: the vendor's `AllowIps` returns `$next($request)` on an empty
 * list, which its own config comment states outright ("All IP's are allowed when empty").
 * So the file promised a closed door beside a middleware that opened one, and the test
 * could not tell.
 *
 * A REQUEST, therefore. Metrics on, nobody named, and the answer must not be a 200.
 */
it('refuses every address when metrics are on and none were named', function (): void {
    // THE MIDDLEWARE ITSELF, not a request to `/prometheus`. That route is registered at
    // boot from `prometheus.enabled`, so toggling the config inside a test never registers
    // it — and a request would then 404 because the route is absent, which is the same
    // answer a refusal gives. Passing for that reason is the failure this whole test was
    // rewritten to escape.
    $refused = false;

    try {
        config(['prometheus.allowed_ips' => []]);

        app(AllowNamedIpsOnly::class)->handle(
            Request::create('/prometheus', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']),
            fn (): Response => new Response('metrics', 200),
        );
    } catch (NotFoundHttpException) {
        $refused = true;
    }

    expect($refused)->toBeTrue('an empty allow-list admitted a caller');

    // And the wiring: the refusal is only reached if the config names this middleware.
    expect((require base_path('config/prometheus.php'))['middleware'])->toContain(AllowNamedIpsOnly::class);
})->group('security');

/** And serves a named address, so the refusal is a rule rather than a wall. */
it('serves metrics to an address the operator named', function (): void {
    config(['prometheus.allowed_ips' => ['203.0.113.7']]);

    $response = app(AllowNamedIpsOnly::class)->handle(
        Request::create('/prometheus', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']),
        fn (): Response => new Response('metrics', 200),
    );

    expect($response->getStatusCode())->toBe(200);
})->group('security');

/** An address that was not named gets the same answer as nobody at all. */
it('refuses an address the operator did not name', function (): void {
    config(['prometheus.allowed_ips' => ['10.9.9.9']]);

    expect(fn () => app(AllowNamedIpsOnly::class)->handle(
        Request::create('/prometheus', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']),
        fn (): Response => new Response('metrics', 200),
    ))->toThrow(NotFoundHttpException::class);
})->group('security');
