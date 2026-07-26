<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Assets;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * An object-storage {@see BrandAssetStore} for a horizontally-scaled deployment: it
 * writes to whatever disk it is given (typically `s3`) and, when a CDN base URL is
 * configured, returns a CDN URL instead of the disk's own. It talks only to the
 * Laravel {@see Filesystem} contract — no AWS SDK is referenced here, so the package
 * keeps no hard S3 dependency; the host binds this in place of the local store.
 */
class ObjectStorageBrandAssetStore implements BrandAssetStore
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly EnvironmentContext $environment,
        private readonly string $basePath = 'brand',
        private readonly ?string $cdnBaseUrl = null,
    ) {}

    public function put(string $kind, UploadedFile $file): string
    {
        $path = $this->disk->putFileAs($this->directory(), $file, $this->filename($kind, $file), 'public');

        if ($path === false) {
            throw new RuntimeException('Failed to store brand asset.');
        }

        if ($this->cdnBaseUrl !== null && $this->cdnBaseUrl !== '') {
            return rtrim($this->cdnBaseUrl, '/').'/'.$path;
        }

        return $this->disk->url($path);
    }

    public function forget(?string $url): void
    {
        $path = AssetPath::fromUrl($url, $this->basePath);

        if ($path !== null && $this->disk->exists($path)) {
            $this->disk->delete($path);
        }
    }

    protected function directory(): string
    {
        $environment = $this->environment->has()
            ? $this->environment->requireEnvironment()->environmentKey()
            : 'shared';

        return $this->basePath.'/'.$environment;
    }

    protected function filename(string $kind, UploadedFile $file): string
    {
        // `?:` (not is_string/??) so the guard is meaningful on both Laravel majors:
        // UploadedFile::extension() is `string` on 12 but `?string` on 13.
        $extension = $file->extension() ?: 'bin';

        $slug = preg_replace('/[^a-z0-9]/', '', strtolower($kind)) ?? '';
        $slug = $slug === '' ? 'asset' : $slug;

        return $slug.'-'.bin2hex(random_bytes(8)).'.'.$extension;
    }
}
