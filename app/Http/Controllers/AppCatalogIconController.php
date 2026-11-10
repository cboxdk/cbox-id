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
        // Either variant, light or dark, is addressed by the hash of its own bytes.
        $uri = array_find([$entry->icon_data, $entry->icon_dark_data], static fn (?string $data): bool => $data !== null && hash_equals(hash('sha256', $data), $hash));
        abort_if($uri === null, 404);
        [$header, $encoded] = explode(',', $uri, 2);
        $mime = substr($header, 5, -7);
        $bytes = base64_decode($encoded, true);
        abort_if($bytes === false, 404);

        return response($bytes, 200, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=86400', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
