<?php

declare(strict_types=1);

namespace App\Platform\Api;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * A route served at a second path, with the same handler and the same middleware — how a
 * path moves while its callers move with it (expand, then contract).
 *
 * Read from the router, so the copy is the original's to the letter, including a route a
 * package registered. When the original is not there (the package switched it off), there
 * is nothing to copy and nothing is registered.
 */
final class RouteMirror
{
    /**
     * @param  list<string>  $middleware  added after the original's own
     */
    public static function mirror(Router $router, string $name, string $uri, string $as, array $middleware = []): ?Route
    {
        // Looked for by walking the table: while routes are still being registered the name
        // index is not built yet, and a route named after it was added is not in it.
        $original = null;

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getName() === $name) {
                $original = $route;

                break;
            }
        }

        if (! $original instanceof Route) {
            return null;
        }

        $uses = $original->getAction('uses');

        if (! is_string($uses) && ! $uses instanceof \Closure) {
            return null;
        }

        $copy = $router->addRoute($original->methods(), $uri, $uses)
            ->middleware([...array_values(array_filter($original->middleware(), 'is_string')), ...$middleware])
            ->name($as);

        foreach ($original->wheres as $parameter => $pattern) {
            if (is_string($pattern)) {
                $copy->where($parameter, $pattern);
            }
        }

        foreach ($original->defaults as $key => $value) {
            $copy->defaults($key, $value);
        }

        return $copy;
    }

    /**
     * Every route whose name starts with $prefix, served again under $uriPrefix in place of
     * its own $from prefix.
     *
     * @param  list<string>  $middleware
     */
    public static function mirrorAll(Router $router, string $prefix, string $from, string $uriPrefix, string $as, array $middleware = []): void
    {
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name) || ! str_starts_with($name, $prefix) || ! str_starts_with($route->uri(), $from)) {
                continue;
            }

            self::mirror($router, $name, $uriPrefix.substr($route->uri(), strlen($from)), $as.substr($name, strlen($prefix)), $middleware);
        }
    }
}
