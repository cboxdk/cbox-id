<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Account\AccountController;
use App\Http\Controllers\Api\Account\EnvironmentController;
use App\Http\Controllers\Api\Account\MemberController;
use App\Http\Controllers\Api\AppManifestController;
use App\Http\Controllers\Api\Environment\OrganizationController;
use App\Http\Controllers\Api\Environment\UserController;
use App\Http\Controllers\Api\VaultController;
use Cbox\Id\Api\Http\Middleware\ResolveEnvironment;
use Illuminate\Support\Facades\Route;

/*
 * Customer-facing REST API. Every route resolves the environment from the request
 * host (ResolveEnvironment) so the platform's deny-by-default tenancy scope engages,
 * and authenticates a scoped OAuth access token via the `scope:` middleware.
 *
 * Token Vault (v1): provision + grant downstream credentials (vault.manage), and
 * lease them to an authorized agent client (vault.lease).
 */
// App authorization manifest — the PUSH transport. An app declares its own
// roles/permissions with an `apps.manifest`-scoped token.
Route::middleware([ResolveEnvironment::class, 'throttle:60,1'])
    ->prefix('v1/apps')
    ->group(function (): void {
        Route::post('manifest', [AppManifestController::class, 'push'])
            ->middleware('scope:apps.manifest');
    });

/*
 * Account management plane (GLOBAL). Unlike the environment-scoped routes above,
 * these do NOT resolve an environment (ResolveEnvironment) — an account operates
 * above every environment it owns. Authenticated by a `Bearer cbid_acc_…` account API
 * key via `account.api`, with a required capability on write routes so a read-only
 * key can't mutate. Intended to be served on the platform-root host
 * (e.g. api.cboxid.com); an environment-scoped credential is never accepted here.
 */
// The account-plane OpenAPI 3.1 spec — public, so tooling and generated clients can
// fetch the contract without a key.
Route::get('v1/openapi.yaml', function () {
    $spec = @file_get_contents(resource_path('openapi/account.yaml'));
    abort_if($spec === false, 404);

    return response($spec, 200, ['Content-Type' => 'application/yaml']);
})->name('api.openapi');

Route::middleware('throttle:120,1')
    ->prefix('v1/account')
    ->group(function (): void {
        // Every route resolves the key exactly once, with the capability its data
        // requires — reads are gated too, so a leaked developer/CI key can't
        // enumerate the member roster (PII) or read billing.
        Route::get('/', [AccountController::class, 'show'])->middleware('account.api');

        Route::get('environments', [EnvironmentController::class, 'index'])->middleware('account.api');
        Route::post('environments', [EnvironmentController::class, 'store'])->middleware('account.api:manage-environments');

        Route::get('members', [MemberController::class, 'index'])->middleware('account.api:read-members');
        Route::post('members', [MemberController::class, 'store'])->middleware('account.api:manage-members');
    });

/*
 * Environment management plane (SCOPED). Served on an environment's OWN host
 * ({slug}.cboxid.com or a custom domain): ResolveEnvironment pins the environment
 * from the host, then a `Bearer cbid_env_…` key is authenticated by `env.api` and
 * checked against the fine-grained scope each route requires. Because the key model
 * is hard environment-scoped, a key minted for another environment can't resolve
 * here at all — the credential is bound to the host it was created for. This is the
 * API apps use for day-to-day org/user provisioning (WorkOS/Clerk-style).
 */
Route::get('v1/environment/openapi.yaml', function () {
    $spec = @file_get_contents(resource_path('openapi/environment.yaml'));
    abort_if($spec === false, 404);

    return response($spec, 200, ['Content-Type' => 'application/yaml']);
})->middleware(ResolveEnvironment::class)->name('api.environment.openapi');

Route::middleware([ResolveEnvironment::class, 'throttle:240,1'])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('organizations', [OrganizationController::class, 'index'])->middleware('env.api:organizations:read');
        Route::post('organizations', [OrganizationController::class, 'store'])->middleware('env.api:organizations:write');
        Route::get('organizations/{id}', [OrganizationController::class, 'show'])->middleware('env.api:organizations:read');

        Route::get('users', [UserController::class, 'index'])->middleware('env.api:users:read');
        Route::post('users', [UserController::class, 'store'])->middleware('env.api:users:write');
        Route::get('users/{id}', [UserController::class, 'show'])->middleware('env.api:users:read');
        Route::delete('users/{id}', [UserController::class, 'destroy'])->middleware('env.api:users:write');
    });

Route::middleware([ResolveEnvironment::class, 'throttle:120,1'])
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
