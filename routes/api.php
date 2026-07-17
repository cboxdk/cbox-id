<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AppManifestController;
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
