<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Http\Controllers\Api\ActionController;
use Illuminate\Support\Facades\Route;

/**
 * Registers a REST route for every action on a plane, from the registry: its method and
 * path, the scope its key must carry, and the action it runs. Called inside the plane's own
 * route group, which supplies the host, the prefix and the rate limiter.
 */
final class ActionRoutes
{
    public static function environment(): void
    {
        foreach (app(ActionRegistry::class)->forPlane(ActionPlane::Environment) as $action) {
            Route::match([$action->method], ltrim($action->path, '/'), ActionController::class)
                ->defaults('action', $action->name)
                ->middleware('env.api:'.$action->scope)
                ->name('api.actions.'.$action->name);
        }
    }
}
