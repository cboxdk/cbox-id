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
 * `unavailable` is the "More from Cbox" section: apps the person can ask for, each with its
 * `admission_url` and no `launch_url`.
 */
final class AppCatalogFeedController
{
    private const array FIELDS = ['key', 'name', 'description', 'purpose', 'hue', 'launch_url', 'admission_url', 'icon_url', 'icon_dark_url', 'available'];

    public function __invoke(AppLauncher $launcher): JsonResponse
    {
        $catalog = $launcher->catalog();
        $only = static fn (array $app): array => array_intersect_key($app, array_flip(self::FIELDS));
        $catalog = ['apps' => array_map($only, $catalog['apps']), 'unavailable' => array_map($only, $catalog['unavailable'])];

        return response()->json([
            'apps' => $catalog['apps'],
            'unavailable' => $catalog['unavailable'],
            'revision' => AppCatalog::revision($catalog),
            'refresh_after' => 60,
        ])->header('Cache-Control', 'private, no-store');
    }
}
