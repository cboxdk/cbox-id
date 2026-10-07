<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Http\Controllers\Api\ActionController;
use App\Http\Middleware\AuthenticateDelegatedApi;
use App\Http\Middleware\AuthenticateEnvironmentApi;
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

    /**
     * The operator API: only a platform operator's delegated token, never a key
     * ({@see AuthenticateDelegatedApi}).
     */
    public static function platform(): void
    {
        self::register(ActionPlane::Platform);
    }

    /** A person's own account: only a token that person delegated, never a key. */
    public static function account(): void
    {
        self::register(ActionPlane::Account);
    }

    /**
     * The route middleware an action is guarded by on its plane. An environment's actions
     * take a person's access token as well as a key ({@see AuthenticateEnvironmentApi::DELEGATED}):
     * the principal it becomes asks what the person may do, the same as on `/mcp`. The
     * platform and account planes take only a delegated token ({@see AuthenticateDelegatedApi}).
     */
    public static function middleware(ActionDefinition $action): string
    {
        return match ($action->plane) {
            ActionPlane::Environment => 'env.api:'.$action->scope.','.AuthenticateEnvironmentApi::DELEGATED,
            ActionPlane::Workspace => 'workspace.api:'.$action->scope,
            ActionPlane::Platform, ActionPlane::Account => 'delegated.api:'.$action->plane->value.','.$action->scope,
        };
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
