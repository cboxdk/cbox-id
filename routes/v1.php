<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ApiDocsController;
use App\Http\Controllers\Api\V1\ApiIndexController;
use App\Http\Controllers\Api\V1\ApiResourceMetadataController;
use App\Http\Controllers\Api\V1\LegacyPathController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Middleware\ResolveApiEnvironment;
use App\Http\Middleware\V1Parameters;
use App\Http\Middleware\V1Responses;
use App\Platform\Api\ApiHost;
use App\Platform\Api\V1\V1Routes;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 * THE API HOST: `https://api.cboxid.com/v1/…` for every management plane (plan §2.3).
 *
 * Bound to the host `CBOX_ID_API_HOST` names, and to nothing but it — {@see
 * \App\Http\Middleware\FenceApiHost} keeps the rest of the application off this host. The
 * `api` group only: no session, no cookie, no CSRF, no page. The environment comes from the
 * credential ({@see ResolveApiEnvironment}), the shapes are written once on the way out
 * ({@see V1Responses}), and `/api/v1` keeps answering beside all of it until its callers
 * have moved.
 */
$host = ApiHost::name();

/*
 * NO API HOST, NO `/v1`. A deployment that names none (production before the cut-over, a
 * self-hosted install without `CBOX_ID_API_HOST`) keeps `/api/v1` only. Registered on no
 * domain, these routes would answer on every issuer host and, worse, replace the issuer's
 * own RFC 9728 `/.well-known/oauth-protected-resource` (same domain, same path).
 */
if ($host === null) {
    return;
}

Route::middleware(['api', V1Responses::class, ResolveApiEnvironment::class, V1Parameters::class])->domain($host)->group(function (Router $router): void {
    V1Routes::register($router);
});

Route::middleware(['api', 'throttle:120,1'])->domain($host)->group(function (): void {
    // The contract, one document for every plane, and the reference rendered from it.
    // The base itself, which a person may open: what it is and where its reference lives.
    Route::get('v1', ApiIndexController::class)->name('v1.index');
    Route::get('openapi.json', OpenApiController::class)->name('v1.openapi');
    Route::get('docs', ApiDocsController::class)->name('v1.docs');
    // A problem's `type` resolves to its entry in the reference.
    Route::get('problems/{code}', [ApiDocsController::class, 'problem'])->where('code', '[a-z0-9_]+')->name('v1.problem');
    // RFC 9728: what `https://api.cboxid.com` is, and where its tokens come from.
    Route::get('.well-known/oauth-protected-resource', ApiResourceMetadataController::class)->name('v1.resource-metadata');
});

// The transition: an old path on the new base answers as the old path does (see the class).
Route::middleware(['api'])->domain($host)->any('v1/{legacy}', LegacyPathController::class)->where('legacy', '.*')->fallback()->name('v1.legacy');
