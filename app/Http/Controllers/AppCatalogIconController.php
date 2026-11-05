<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\AppCatalog\CatalogEntry;
use Illuminate\Http\Response;

final class AppCatalogIconController
{
    public function __invoke(string $id, string $hash): Response
    {
        $entry = CatalogEntry::query()->whereKey($id)->firstOrFail();
        $uri = $entry->icon_data;
        abort_if($uri === null || ! hash_equals(hash('sha256', $uri), $hash), 404);
        [$header, $encoded] = explode(',', $uri, 2);
        $mime = substr($header, 5, -7);
        $bytes = base64_decode($encoded, true);
        abort_if($bytes === false, 404);

        return response($bytes, 200, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=86400', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
