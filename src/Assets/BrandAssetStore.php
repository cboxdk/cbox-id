<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Assets;

use Illuminate\Http\UploadedFile;

/**
 * Stores tenant brand assets (logo, favicon) and returns a URL safe to render in an
 * `<img src>`. Kept behind a contract so the object-storage backend stays optional —
 * the framework has no hard S3 dependency; the default {@see LocalBrandAssetStore}
 * writes to a Laravel disk, and {@see ObjectStorageBrandAssetStore} is a drop-in for
 * a horizontally-scaled deployment.
 */
interface BrandAssetStore
{
    /**
     * Persist an uploaded asset and return its public URL. `$kind` is a short label
     * (e.g. `logo`, `favicon`) used in the stored filename.
     */
    public function put(string $kind, UploadedFile $file): string;

    /** Best-effort removal of a previously stored asset by its URL. */
    public function forget(?string $url): void;
}
