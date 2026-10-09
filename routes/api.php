<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AppManifestController;
use App\Http\Controllers\Api\Environment\ActionApprovalController;
use App\Http\Controllers\Api\PipeTokenController;
use App\Http\Controllers\Api\VaultController;
use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Platform\Actions\ActionRoutes;
use Cbox\Id\Api\Http\Middleware\ResolveEnvironment;
use Illuminate\Support\Facades\Route;

/*
 * Customer-facing REST API. Every route resolves the environment from the request
 * host (ResolveEnvironment) so the platform's deny-by-default tenancy scope engages,
 * and authenticates a scoped OAuth access token via the `scope:` middleware.
 *
 * Token Vault (v1): provision + grant downstream credentials (vault.manage), and
 * lease them to an authorized agent client (vault.lease).
 *
 * Throttling is by NAMED limiter (`api-<plane>`, registered in App\Http\ApiRateLimiters),
 * not by `throttle:<n>,<m>`: the bare form keys on the client IP, so every tenant behind
 * one NAT — which is every hosted CI runner — shared a single bucket. The named limiters
 * key on the CREDENTIAL. Budgets live in config/api.php.
 */
// App authorization manifest — the PUSH transport. An app declares its own
// roles/permissions with an `apps.manifest`-scoped token.
Route::middleware([ResolveEnvironment::class, 'throttle:api-apps'])
    ->prefix('v1/apps')
    ->group(function (): void {
        Route::post('manifest', [AppManifestController::class, 'push'])
            ->middleware('scope:apps.manifest');
    });

/*
 * Workspace management plane (GLOBAL). Unlike the environment-scoped routes above, these
 * do NOT resolve an environment (ResolveEnvironment) — a workspace operates above every
 * environment it owns. Authenticated by a `Bearer cbid_ws_…` workspace key via
 * `workspace.api`, with the scope each action requires — bounded by the key's role, so a
 * read-only key can't mutate however it is scoped. Intended to be served on the
 * platform-root host (e.g. api.cboxid.com); an environment-scoped credential is never
 * accepted here.
 *
 * It was `/v1/organization` with `cbid_org_` keys until the console's own word for the
 * thing — a workspace — became the API's too. A clean break, not an alias: see UPGRADING.
 */
// The workspace-plane OpenAPI 3.1 spec — public, so tooling and generated clients can
// fetch the contract without a key.
Route::get('v1/workspace/openapi.yaml', function () {
    $spec = @file_get_contents(resource_path('openapi/workspace.yaml'));
    abort_if($spec === false, 404);

    return response($spec, 200, ['Content-Type' => 'application/yaml']);
})->name('api.workspace.openapi');

Route::middleware('throttle:api-workspace')
    ->prefix('v1/workspace')
    ->group(function (): void {
        // EVERY route here is an action, routed from the registry with the scope it
        // declares (`workspace.api:<scope>`): the key is resolved once, its ROLE must hold
        // the capability the scope needs and the key must carry the scope. Reads are gated
        // too, so a leaked developer/CI key can't enumerate the member roster (PII) or
        // read billing. The console's Projects, Team, Keys and Workspace settings pages
        // run the same actions.
        ActionRoutes::workspace();

        // Where an approval a workspace key's policy asked for stands — only its own.
        Route::get('action-approvals/{id}', [ActionApprovalController::class, 'showForWorkspace'])->middleware('workspace.api');
    });

/*
 * The OPERATOR API (GLOBAL) — the deployment itself, for Cbox staff: standing up customer
 * workspaces, environments, organizations inside any environment, and the operator roster.
 * Served on the platform-root host and resolving no environment, like the workspace plane.
 *
 * NO KEY OF ANY KIND IS ACCEPTED. Every route is `delegated.api:platform,<scope>`: a token
 * the platform root issued a platform OPERATOR (`cbox login` at the root, or an agent
 * signed in there), carrying the `operator:*` scope the action asks for. The console's
 * Platform pages run the very same actions.
 */
Route::get('v1/platform/openapi.yaml', function () {
    $spec = @file_get_contents(resource_path('openapi/platform.yaml'));
    abort_if($spec === false, 404);

    return response($spec, 200, ['Content-Type' => 'application/yaml']);
})->name('api.platform.openapi');

Route::middleware('throttle:api-platform')
    ->prefix('v1/platform')
    ->group(function (): void {
        ActionRoutes::platform();

        // Where an approval a held operator action asked for stands — the operator's own.
        Route::get('action-approvals/{id}', [ActionApprovalController::class, 'showForPerson'])->middleware('delegated.api:platform');
    });

/*
 * A person's OWN ACCOUNT (SCOPED to the environment they belong to, so on its host) — the
 * profile, sessions, app grants, personal API keys and trusted devices of whoever is
 * calling. `delegated.api:account,<scope>`: a token the person delegated, never a key — no
 * management credential acts as a person. On an environment's host that is the
 * environment's own token for its own subject; on the platform root's, the root's token
 * for a workspace member or an operator — each person's account lives where they sign in.
 *
 * Every action here is keyed to the person behind the token; there is no account id in
 * any path, so there is no other account to name.
 */
Route::get('v1/me/openapi.yaml', function () {
    $spec = @file_get_contents(resource_path('openapi/account.yaml'));
    abort_if($spec === false, 404);

    return response($spec, 200, ['Content-Type' => 'application/yaml']);
})->name('api.account.openapi');

Route::middleware([ResolveEnvironment::class, 'throttle:api-account'])
    ->prefix('v1/me')
    ->group(function (): void {
        ActionRoutes::account();

        // Where an approval a held account action asked for stands — the person's own.
        Route::get('action-approvals/{id}', [ActionApprovalController::class, 'showForPerson'])->middleware('delegated.api:account');
    });

/*
 * Environment management plane (SCOPED). Served on an environment's OWN host
 * ({slug}.cboxid.com or a custom domain): ResolveEnvironment pins the environment
 * from the host, then a `Bearer cbid_env_…` key is authenticated by `env.api` and
 * checked against the fine-grained scope each route requires. Because the key model
 * is hard environment-scoped, a key minted for another environment can't resolve
 * here at all — the credential is bound to the host it was created for. This is the
 * API apps use for day-to-day org/user provisioning.
 */
Route::get('v1/environment/openapi.yaml', function () {
    $spec = @file_get_contents(resource_path('openapi/environment.yaml'));
    abort_if($spec === false, 404);

    return response($spec, 200, ['Content-Type' => 'application/yaml']);
})->middleware(ResolveEnvironment::class)->name('api.environment.openapi');

Route::middleware([ResolveEnvironment::class, 'throttle:api-environment'])
    ->prefix('v1')
    ->group(function (): void {
        // EVERY management route here is an ACTION, routed from the action registry — its
        // method, path and scope are declared once, on the action — and run by the one
        // ActionController, the same way the console and MCP run it: organizations, users,
        // members, invitations, roles, apps, APIs, keys and the rest.
        ActionRoutes::environment();

        // Where an action approval this key asked for stands (see ActionApprovalGate).
        // A person's token polls the approvals IT raised, too: no scope, its own only.
        Route::get('action-approvals/{id}', [ActionApprovalController::class, 'show'])->middleware('env.api:,'.AuthenticateEnvironmentApi::DELEGATED);
    });

Route::middleware([ResolveEnvironment::class, 'throttle:api-vault'])
    ->prefix('v1/vault')
    ->group(function (): void {
        Route::post('secrets', [VaultController::class, 'store'])
            ->middleware('scope:vault.manage');
        Route::post('secrets/{id}/rotate', [VaultController::class, 'rotate'])
            ->middleware('scope:vault.manage');
        Route::delete('secrets/{id}', [VaultController::class, 'revoke'])
            ->middleware('scope:vault.manage');
        Route::post('secrets/{id}/grants', [VaultController::class, 'grant'])
            ->middleware('scope:vault.manage');
        Route::delete('secrets/{id}/grants/{clientId}', [VaultController::class, 'revokeGrant'])
            ->middleware('scope:vault.manage');
        Route::post('secrets/{id}/lease', [VaultController::class, 'lease'])
            ->middleware('scope:vault.lease');
        // Pipes: a fresh access token for one person's connected third-party account.
        Route::post('pipes/{provider}/token', [PipeTokenController::class, 'lease'])
            ->middleware('scope:vault.lease');
    });
