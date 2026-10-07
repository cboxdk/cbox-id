<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Http\Controllers\Api\ActionController;
use Illuminate\Support\Facades\Route;

/**
 * Registers a REST route for every action on a plane, from the registry: its method and
 * path, the scope its key must carry, and the action it runs. Called inside the plane's own
 * route group, which supplies the host, the prefix and the rate limiter.
 *
 * The middleware is each plane's own authenticator with the scope as its parameter —
 * `env.api:apis:write`, `workspace.api:projects:write` — so a key of the wrong plane, or
 * without the scope, is refused before the body is read. The principal asks again when the
 * action runs ({@see ActionRunner}); the route is one door of several.
 */
final class ActionRoutes
{
    public static function environment(): void
    {
        self::register(ActionPlane::Environment);
    }

    public static function workspace(): void
    {
        self::register(ActionPlane::Workspace);
    }

    /** The route middleware an action is guarded by on its plane. */
    public static function middleware(ActionDefinition $action): string
    {
        return match ($action->plane) {
            ActionPlane::Environment => 'env.api',
            ActionPlane::Workspace => 'workspace.api',
        }.':'.$action->scope;
    }

    private static function register(ActionPlane $plane): void
    {
        foreach (app(ActionRegistry::class)->forPlane($plane) as $action) {
            Route::match([$action->method], ltrim($action->path, '/'), ActionController::class)
                ->defaults('action', $action->name)
                ->middleware(self::middleware($action))
                ->name('api.actions.'.$action->name);
        }
    }
}
