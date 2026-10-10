<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Http\Controllers;

use Cbox\Id\Whitelabel\Assets\DatabaseBrandAssetStore;
use Cbox\Id\Whitelabel\Models\BrandAsset;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves an uploaded logo or favicon stored by {@see DatabaseBrandAssetStore}.
 *
 * Public, sessionless and the same on every host: the image is drawn on hosted sign-in
 * pages for anonymous visitors, and the name it is asked for by is unguessable. Cached
 * for a year as immutable, because a new upload is a new name.
 */
final class BrandAssetController
{
    public function __invoke(string $path): Response
    {
        $asset = BrandAsset::query()->find($path);

        abort_if($asset === null || ! in_array($asset->mime, DatabaseBrandAssetStore::MIMES, true), 404);

        $contents = base64_decode($asset->contents, true);

        abort_if($contents === false, 404);

        return response($contents, 200, [
            'Content-Type' => $asset->mime,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
