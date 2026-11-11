<?php

declare(strict_types=1);

namespace App\Platform\Api\V1;

use App\Http\Controllers\Api\V1\V1DispatchController;
use App\Http\Middleware\RequireScope;
use App\Http\Middleware\ResolveApiEnvironment;
use Cbox\Id\Api\Http\Middleware\ResolveEnvironment;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * REGISTERS `/v1` FROM THE `/api/v1` TABLE — the same handlers, the same doors, the same
 * scopes, at the paths {@see V1Paths} gives them.
 *
 * Read from the router rather than declared twice, so an operation added to `/api/v1` (an
 * action, a controller route) is on `/v1` the moment it exists, and the two cannot drift
 * while both are served. Each route keeps its own middleware except the host-based
 * environment resolution: on the API host the credential names the environment
 * ({@see ResolveApiEnvironment}, on the whole group).
 *
 * Where two operations share a path — a resource two planes reach, or one update made of two
 * old ones — the route runs them through {@see V1DispatchController}, which keeps each one's
 * own middleware.
 */
final class V1Routes
{
    /** The old parameter the path's `{id}` stands for. */
    public const string ID = 'v1_id';

    /** Old parameters read from the request's input: `old => public`. */
    public const string INPUTS = 'v1_inputs';

    /** The `object` a single resource in the response is. */
    public const string OBJECT = 'v1_object';

    /** The operations one route stands for, when it stands for more than one. */
    public const string VARIANTS = 'v1_variants';

    /** Verbs that end a path: the response is still the resource the path names first. */
    private const array VERBS = ['status', 'access'];

    /**
     * Register every `/v1` route. Call inside the API host's route group.
     */
    public static function register(Router $router): void
    {
        $groups = [];

        foreach (self::operations($router) as $operation) {
            $groups[$operation['method'].' '.$operation['path']][] = $operation;
        }

        foreach ($groups as $operations) {
            $first = $operations[0];
            $name = 'v1.'.strtolower($first['method']).str_replace(['/', '{', '}'], ['.', '', ''], $first['path']);
            $object = self::objectFor($first['path']);

            if (count($operations) === 1 && $first['when'] === null) {
                $route = $router->addRoute($first['method'], ltrim('v1'.$first['path'], '/'), $first['uses'])
                    ->middleware($first['middleware'])
                    ->name($name);

                $route->defaults(self::ID, $first['id']);
                $route->defaults(self::INPUTS, $first['inputs']);
                $route->defaults(self::OBJECT, $object);

                if ($first['action'] !== null) {
                    $route->defaults('action', $first['action']);
                }

                continue;
            }

            $route = $router->addRoute($first['method'], ltrim('v1'.$first['path'], '/'), V1DispatchController::class)->name($name);
            $route->defaults(self::OBJECT, $object);
            $route->defaults(self::VARIANTS, $operations);
        }
    }

    /**
     * Every `/api/v1` operation that has a place on `/v1`, with that place.
     *
     * @return list<array{method: string, path: string, id: string|null, inputs: array<string, string>, when: string|null, plane: string, uses: string, action: string|null, middleware: list<string>, legacy: string}>
     */
    public static function operations(Router $router): array
    {
        $operations = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/') && $uri !== 'api/v1') {
                continue;
            }

            $uses = $route->getAction('uses');

            if (! is_string($uses)) {
                continue;
            }

            $old = '/'.ltrim(substr($uri, strlen('api/v1')), '/');

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $target = V1Paths::target($method, $old);

                if ($target === null) {
                    continue;
                }

                $action = $route->defaults['action'] ?? null;

                $operations[] = [
                    ...$target,
                    'plane' => self::planeOf($old),
                    'uses' => $uses,
                    'action' => is_string($action) ? $action : null,
                    'middleware' => self::middlewareOf($route, $target['path']),
                    'legacy' => $method.' /api/v1'.($old === '/' ? '' : $old),
                ];
            }
        }

        return $operations;
    }

    /**
     * What the plane of an old path is, for routing a shared path to the plane the
     * credential speaks for first.
     */
    public static function planeOf(string $old): string
    {
        return match (true) {
            $old === '/workspace' || str_starts_with($old, '/workspace/') => 'organization',
            str_starts_with($old, '/platform/') => 'admin',
            str_starts_with($old, '/me/') || str_starts_with($old, '/approvals') || $old === '/authenticator/me' => 'account',
            default => 'environment',
        };
    }

    /**
     * The `object` a resource at this path is: the resource the path names, singular — or,
     * for a sub-resource (`/users/{id}/sessions`), the sub-resource.
     */
    public static function objectFor(string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));

        if (in_array($segments[0] ?? '', ['me', 'admin'], true) && count($segments) > 1) {
            array_shift($segments);
        }

        $noun = $segments[0] ?? 'object';

        if (count($segments) === 3 && str_ends_with($segments[2], 's') && ! in_array($segments[2], self::VERBS, true)) {
            $noun = $segments[2];
        }

        return str_replace('-', '_', Str::singular($noun));
    }

    /**
     * A route's own middleware on `/v1`: without the `api` group (the API host's group
     * carries it) and without host-based environment resolution. `/inspect` takes the lease
     * scope as well as its own, so the folded-in `/status` caller loses nothing.
     *
     * @return list<string>
     */
    private static function middlewareOf(Route $route, string $path): array
    {
        $middleware = [];

        foreach ($route->middleware() as $entry) {
            if (! is_string($entry) || $entry === 'api' || $entry === ResolveEnvironment::class) {
                continue;
            }

            if ($path === '/service-connections/{id}/inspect' && in_array($entry, ['scope:service_connections.inspect', RequireScope::class.':service_connections.inspect'], true)) {
                $entry = 'scope:service_connections.inspect|service_connections.lease';
            }

            $middleware[] = $entry;
        }

        return $middleware;
    }
}
