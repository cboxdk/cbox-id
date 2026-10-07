<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AppManifestController;
use App\Http\Controllers\Api\Environment\ActionApprovalController;
use App\Http\Controllers\Api\Environment\ApiKeyController;
use App\Http\Controllers\Api\Environment\EnvironmentRoleController;
use App\Http\Controllers\Api\Environment\InvitationController;
use App\Http\Controllers\Api\Environment\MemberController as EnvironmentMemberController;
use App\Http\Controllers\Api\Environment\MemberRoleController;
use App\Http\Controllers\Api\Environment\OrganizationController;
use App\Http\Controllers\Api\Environment\RoleController;
use App\Http\Controllers\Api\Environment\SupportSessionController;
use App\Http\Controllers\Api\Environment\UserController;
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
        Route::get('organizations', [OrganizationController::class, 'index'])->middleware('env.api:organizations:read');
        Route::post('organizations', [OrganizationController::class, 'store'])->middleware('env.api:organizations:write');
        Route::get('organizations/{id}', [OrganizationController::class, 'show'])->middleware('env.api:organizations:read');
        Route::patch('organizations/{id}', [OrganizationController::class, 'update'])->middleware('env.api:organizations:write');
        Route::delete('organizations/{id}', [OrganizationController::class, 'destroy'])->middleware('env.api:organizations:write');
        // Handing an organization over is an organization-level act (the scope's own
        // description says so), not a change to one member's tier.
        Route::post('organizations/{id}/transfer-ownership', [EnvironmentMemberController::class, 'transferOwnership'])->middleware('env.api:organizations:write');

        Route::get('organizations/{id}/members', [EnvironmentMemberController::class, 'index'])->middleware('env.api:members:read');
        Route::post('organizations/{id}/members', [EnvironmentMemberController::class, 'store'])->middleware('env.api:members:write');
        Route::patch('organizations/{id}/members/{userId}', [EnvironmentMemberController::class, 'update'])->middleware('env.api:members:write');
        Route::delete('organizations/{id}/members/{userId}', [EnvironmentMemberController::class, 'destroy'])->middleware('env.api:members:write');

        Route::get('organizations/{id}/members/{userId}/roles', [MemberRoleController::class, 'index'])->middleware('env.api:roles:read');
        Route::put('organizations/{id}/members/{userId}/roles/{roleId}', [MemberRoleController::class, 'update'])->middleware('env.api:roles:write');
        Route::delete('organizations/{id}/members/{userId}/roles/{roleId}', [MemberRoleController::class, 'destroy'])->middleware('env.api:roles:write');

        Route::get('organizations/{id}/invitations', [InvitationController::class, 'index'])->middleware('env.api:invitations:read');
        Route::post('organizations/{id}/invitations', [InvitationController::class, 'store'])->middleware('env.api:invitations:write');
        Route::delete('organizations/{id}/invitations/{invitationId}', [InvitationController::class, 'destroy'])->middleware('env.api:invitations:write');
        Route::post('organizations/{id}/invitations/{invitationId}/resend', [InvitationController::class, 'resend'])->middleware('env.api:invitations:write');

        Route::get('organizations/{id}/api-keys', [ApiKeyController::class, 'index'])->middleware('env.api:api_keys:read');
        Route::delete('api-keys/{id}', [ApiKeyController::class, 'destroy'])->middleware('env.api:api_keys:write');

        Route::get('users', [UserController::class, 'index'])->middleware('env.api:users:read');
        Route::post('users', [UserController::class, 'store'])->middleware('env.api:users:write');
        Route::get('users/{id}', [UserController::class, 'show'])->middleware('env.api:users:read');
        Route::delete('users/{id}', [UserController::class, 'destroy'])->middleware('env.api:users:write');

        // Staff: roles held everywhere in the environment rather than in one organization.
        Route::get('users/{id}/environment-roles', [EnvironmentRoleController::class, 'index'])->middleware('env.api:roles:read');
        Route::get('users/{id}/environment-roles/{roleId}', [EnvironmentRoleController::class, 'show'])->middleware('env.api:roles:read');
        Route::put('users/{id}/environment-roles/{roleId}', [EnvironmentRoleController::class, 'update'])->middleware('env.api:roles:write');
        Route::delete('users/{id}/environment-roles/{roleId}', [EnvironmentRoleController::class, 'destroy'])->middleware('env.api:roles:write');

        Route::get('roles', [RoleController::class, 'index'])->middleware('env.api:roles:read');

        // Everything that is an ACTION is routed from the action registry — its method,
        // path and scope are declared once, on the action — and run by the one
        // ActionController, the same way the console and MCP run it. Areas move here as
        // they become actions; the routes above are the ones still waiting.
        ActionRoutes::environment();

        // Where an action approval this key asked for stands (see ActionApprovalGate).
        // A person's token polls the approvals IT raised, too: no scope, its own only.
        Route::get('action-approvals/{id}', [ActionApprovalController::class, 'show'])->middleware('env.api:,'.AuthenticateEnvironmentApi::DELEGATED);

        Route::post('support-sessions', [SupportSessionController::class, 'store'])->middleware('env.api:support:write');
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
    });
