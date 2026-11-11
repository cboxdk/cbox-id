<?php

declare(strict_types=1);

use App\Platform\Api\ApiHost;
use App\Platform\Api\V1\V1OpenApi;
use App\Platform\Api\V1\V1Paths;
use App\Platform\Api\V1\V1Routes;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
| THE API HOST'S PATHS OBEY THE STANDARD (§4), over the route table itself:
| `/v1/<resource>[/{id}[/<sub-resource>|/<verb>]]` after the `me`/`admin` plane prefix — at
| most three segments, at most one parameter and only in the `{id}` position, never three
| literal segments, PUT only for a whole replacement, one resource per concept.
*/

/**
 * The `/v1` routes on the API host, without the transition route (an old path on the new
 * base, which goes with `/api/v1`).
 *
 * @return list<LaravelRoute>
 */
function v1Routes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static fn (LaravelRoute $route): bool => $route->getDomain() === ApiHost::name()
            && str_starts_with($route->uri(), 'v1/')
            && $route->getName() !== 'v1.legacy',
    ));
}

/** @return list<string> the segments after `/v1` and the plane prefix */
function v1Segments(LaravelRoute $route): array
{
    $segments = explode('/', substr($route->uri(), strlen('v1/')));

    if (in_array($segments[0], ['me', 'admin'], true) && count($segments) > 1) {
        array_shift($segments);
    }

    return $segments;
}

it('serves every management operation on the API host', function (): void {
    expect(count(v1Routes()))->toBeGreaterThan(300);
});

it('keeps every path within three segments after the plane prefix', function (): void {
    $long = array_map(static fn (LaravelRoute $route): string => $route->uri(), array_filter(v1Routes(), static fn (LaravelRoute $route): bool => count(v1Segments($route)) > 3));

    expect($long)->toBe([]);
});

it('names at most one parameter, always {id} in the id position', function (): void {
    $wrong = [];

    foreach (v1Routes() as $route) {
        $segments = v1Segments($route);
        $parameters = array_keys(array_filter($segments, static fn (string $segment): bool => str_starts_with($segment, '{')));

        if (count($parameters) > 1 || ($parameters !== [] && ($parameters[0] !== 1 || $segments[1] !== '{id}'))) {
            $wrong[] = $route->uri();
        }
    }

    expect($wrong)->toBe([]);
});

it('never strings three literal segments together', function (): void {
    $wrong = array_map(
        static fn (LaravelRoute $route): string => $route->uri(),
        array_filter(v1Routes(), static fn (LaravelRoute $route): bool => count(v1Segments($route)) === 3 && ! str_starts_with(v1Segments($route)[1], '{')),
    );

    expect($wrong)->toBe([]);
});

it('uses PUT only for a whole replacement', function (): void {
    $wrong = [];

    foreach (v1Routes() as $route) {
        if (in_array('PUT', $route->methods(), true)) {
            $path = '/'.implode('/', v1Segments($route));

            if (! array_key_exists($path, V1Paths::WHOLE_REPLACEMENTS)) {
                $wrong[] = $route->uri();
            }
        }
    }

    expect($wrong)->toBe([]);
});

it('has one resource per concept', function (): void {
    $uris = array_map(static fn (LaravelRoute $route): string => $route->uri(), v1Routes());

    foreach (['token-vault', 'vault/', 'sso/domains', 'action-approvals', 'agent-requests', '/workspace', 'v1/platform', 'v1/keys'] as $retired) {
        expect(array_filter($uris, static fn (string $uri): bool => str_contains($uri, $retired)))->toBe([], "a route still says {$retired}");
    }
});

it('gives every /api/v1 operation a place on /v1, or says why not', function (): void {
    $operations = V1Routes::operations(app(Router::class));
    $placed = [];

    foreach ($operations as $operation) {
        $placed[$operation['legacy']] = true;
    }

    $missing = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $legacy = $method.' /'.$route->uri();
            $old = substr('/'.$route->uri(), strlen('/api/v1'));

            // Not on /v1 on purpose: V1Paths::MOVED says so with a null.
            if (array_key_exists($method.' '.$old, V1Paths::MOVED) && V1Paths::MOVED[$method.' '.$old] === null) {
                continue;
            }

            if (! isset($placed[$legacy])) {
                $missing[] = $legacy;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('documents every /v1 operation in openapi.json, and nothing that is not served', function (): void {
    $spec = json_decode((string) file_get_contents(V1OpenApi::path()), true);
    $served = [];

    foreach (v1Routes() as $route) {
        foreach ($route->methods() as $method) {
            if ($method !== 'HEAD') {
                $served[strtolower($method).' /'.$route->uri()] = true;
            }
        }
    }

    $documented = [];

    foreach ($spec['paths'] as $path => $item) {
        foreach (array_keys($item) as $method) {
            $documented[$method.' '.$path] = true;
        }
    }

    expect(array_keys(array_diff_key($documented, $served)))->toBe([])
        // Operations the plane documents leave out on purpose (the framework's own routes)
        // are allowed to be missing here, but no more than a handful.
        ->and(count(array_diff_key($served, $documented)))->toBeLessThan(25);
});

it('keeps the committed openapi.json in step with the plane documents', function (): void {
    $this->artisan('openapi:build', ['--check' => true])->assertSuccessful();
});
