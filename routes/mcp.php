<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Discovery\AuthorizationServerMetadataController;
use App\Http\Controllers\Api\Discovery\ProtectedResourceMetadataController;
use App\Http\Controllers\Api\OAuth\ClientRegistrationController;
use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\IdServer;
use App\Platform\OAuth\RootMcpOAuth;
use App\Platform\PlaneResolver;
use App\Support\CliClient;
use Cbox\Id\Api\ApiServiceProvider;
use Cbox\Id\Api\Http\Controllers\DeviceAuthorizationController;
use Cbox\Id\Api\Http\Middleware\CanonicalIssuerHost;
use Cbox\Id\Api\Http\Middleware\NoStore;
use Cbox\Id\Api\Http\Middleware\ResolveEnvironment;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
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

/*
 * RFC 8628 device authorization — re-registered here, over the framework's own route, with
 * ONE change: the first-party wall instead of the issuer wall.
 *
 * The `cbox` CLI signs in with the device grant, and a person of the platform root — a
 * workspace's team, an operator — signs in AT THE ROOT, whose `/mcp` is their one
 * connection to the workspace, its environments, their account and the deployment
 * (`App\Platform\OAuth\RootDelegatedAccess`). The root serves the token endpoint for a
 * platform-owned first-party client already (`plane:first-party`,
 * {@see PlaneResolver::servesFirstPartyIssuer()}); this is the one endpoint of the grant
 * that was still behind `plane:issuer`, so `cbox login` against the root stopped at its
 * first request. The root's CLI client ({@see CliClient}) is exactly such a client, and no
 * other is admitted there: the wall that keeps the root from being anybody's identity
 * provider — discovery, registration, the other grants — stays where it is.
 *
 * Everywhere else the answer is the framework's: on a tenant host, and on a single-tenant
 * install, `plane:first-party` admits every client `plane:issuer` did. Registered here, in
 * the file loaded after every other, so this definition is the one the router keeps; the
 * throttle and `no-store` are the framework's own.
 */
Route::middleware([ResolveEnvironment::class, 'plane:first-party', 'throttle:30,1', NoStore::class])
    ->post('/oauth/device_authorization', DeviceAuthorizationController::class);

/*
 * WHAT AN MCP CLIENT READS AND WRITES BEFORE IT SENDS ANYBODY TO SIGN IN — re-registered
 * here, over the framework's own routes, with ONE change: `plane:mcp-discovery` instead of
 * the issuer wall.
 *
 * At the platform root `/mcp` is one connection for a workspace's whole team, and an MCP
 * client knows nothing but its URL: the `401` points at the resource's metadata, that names
 * the authorization server, its metadata names the registration endpoint. All three were
 * behind `plane:issuer`, so on the root the chain stopped at its first link. Each now
 * answers there while `api.mcp.root_oauth` is on, and each answers with the MCP slice of
 * itself ({@see RootMcpOAuth}): the resource metadata for `/mcp` alone, an RFC 8414
 * document written for the root with no OpenID Connect in it, registration in the `mcp`
 * profile without RFC 7592 management. OpenID Connect discovery, the management endpoints,
 * UserInfo, PAR, SCIM and SAML stay on the framework's routes, behind `plane:issuer`.
 *
 * Everywhere else `plane:mcp-discovery` admits what `plane:issuer` did and the controllers
 * are the framework's answer, so a tenant's host and a single-tenant install see no
 * difference. Middleware as the framework registers each route — the throttles, `no-store`,
 * the canonical-host rule for the documents, the per-address registration ceiling.
 */
Route::middleware([ResolveEnvironment::class, 'plane:mcp-discovery', 'throttle:300,1', CanonicalIssuerHost::class])->group(function (): void {
    Route::get('/.well-known/oauth-authorization-server', AuthorizationServerMetadataController::class);
    Route::get('/.well-known/oauth-protected-resource/{path}', [ProtectedResourceMetadataController::class, 'show'])
        ->where('path', '.+');
});

Route::middleware([ResolveEnvironment::class, 'plane:mcp-discovery', 'web', 'throttle:30,1', NoStore::class])
    ->withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class])
    ->post('/oauth/register', ClientRegistrationController::class)
    ->middleware('throttle:'.ApiServiceProvider::REGISTRATION_LIMITER);
