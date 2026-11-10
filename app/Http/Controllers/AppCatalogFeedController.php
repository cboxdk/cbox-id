<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppLauncher;
use Illuminate\Http\JsonResponse;

/**
 * The console's app switcher reads the signed-in person's catalog here, same-origin, in the
 * shape every Cbox app serves to `<cbox-app-switcher>` (`{apps, revision, refresh_after}`).
 * ID is the catalog's source, so it reads {@see AppCatalog::forUser()}
 * directly — the same list `GET /api/v1/me/apps` gives other apps — with no OAuth round trip.
 */
final class AppCatalogFeedController
{
    private const array FIELDS = ['key', 'name', 'description', 'launch_url', 'icon_url', 'icon_dark_url'];

    public function __invoke(AppLauncher $launcher): JsonResponse
    {
        $apps = array_map(
            static fn (array $app): array => array_intersect_key($app, array_flip(self::FIELDS)),
            $launcher->apps(),
        );

        return response()->json([
            'apps' => $apps,
            'revision' => hash('sha256', json_encode($apps, JSON_THROW_ON_ERROR)),
            'refresh_after' => 60,
        ])->header('Cache-Control', 'private, no-store');
    }
}
