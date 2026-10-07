<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/**
 * The console's writes, read from the router: every named, non-GET route in the `web`
 * stack. The router rather than any list of our own, because a list is exactly what a new
 * write is forgotten from.
 */
final class WriteRoutes
{
    /**
     * @return array<string, RouteDefinition> Keyed by route name, in name order.
     */
    public static function console(): array
    {
        $writes = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (! in_array('web', $route->gatherMiddleware(), true)) {
                continue;
            }

            $writes[$name] = $route;
        }

        ksort($writes);

        return $writes;
    }
}
