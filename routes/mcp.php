<?php

declare(strict_types=1);

use App\Http\Controllers\Mcp\ProtectedResourceMetadataController;
use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\IdServer;
use Cbox\Id\Api\Http\Middleware\ResolveEnvironment;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

/*
 * The MCP server, on every environment's own host — the same host, and so the same
 * environment, as that environment's REST management API. Loaded outside the `web` group
 * (bootstrap/app.php): no session, no cookies, no CSRF.
 *
 * ResolveEnvironment pins the environment from the host first, so a credential resolves
 * only within it; the limiter is a named one keyed on the credential (App\Http\
 * ApiRateLimiters, budget in config/api.php), like every management-plane route.
 */

// RFC 9728: where an MCP client refused at `/mcp` learns which authorization server to
// use. Public — it names the issuer and the scopes, both already public in discovery — and
// throttled by IP like the framework's other metadata documents, since there is no
// credential to key on.
Route::middleware([ResolveEnvironment::class, 'throttle:300,1'])
    ->get(ProtectedResourceMetadataController::PATH, ProtectedResourceMetadataController::class)
    ->name('mcp.protected-resource');

Route::middleware([ResolveEnvironment::class, 'throttle:api-mcp', AuthenticateMcp::class])->group(function (): void {
    // Without AddWwwAuthenticateHeader, which laravel/mcp attaches to the route and ALSO
    // runs globally: on any 401 from a route carrying it, it overwrites `WWW-Authenticate`
    // with its own Passport/Sanctum-shaped challenge. AuthenticateMcp already answers with
    // the RFC 9728 `resource_metadata` pointer for this host; the package's version would
    // replace it with one that points nowhere.
    Mcp::web('mcp', IdServer::class)
        ->withoutMiddleware(AddWwwAuthenticateHeader::class)
        ->name('mcp');
});
