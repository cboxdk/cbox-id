<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Assets;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * The default {@see BrandAssetStore}: writes to a Laravel filesystem disk (the
 * `public` disk by default), so there is no hard object-storage dependency. Assets
 * are namespaced per environment, with a random filename so one tenant can never
 * guess or overwrite another's.
 */
final class LocalBrandAssetStore implements BrandAssetStore
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly EnvironmentContext $environment,
        private readonly string $basePath = 'brand',
    ) {}

    public function put(string $kind, UploadedFile $file): string
    {
        $path = $this->disk->putFileAs($this->directory(), $file, $this->filename($kind, $file), 'public');

        if ($path === false) {
            throw new RuntimeException('Failed to store brand asset.');
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
