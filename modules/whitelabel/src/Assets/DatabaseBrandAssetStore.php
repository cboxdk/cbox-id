<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Assets;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Whitelabel\Http\Controllers\BrandAssetController;
use Cbox\Id\Whitelabel\Models\BrandAsset;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * THE DEFAULT {@see BrandAssetStore}: the image in the database, served by the application.
 *
 * The disk store this replaced as the default wrote to the `public` disk and handed out
 * `APP_URL/storage/…`. In the production image that URL never worked at all — nothing
 * runs `storage:link`, so `/storage` is not served — and it could not have worked for
 * long if it had: two web replicas each have their own disk, so a logo uploaded through
 * one pod is missing on the other, and every deploy replaces both pods and loses every
 * upload. On cboxid.com an uploaded logo was a broken image on the hosted sign-in page.
 *
 * The database is the one store every replica, the worker and the next deploy share, and a
 * logo (1 MB at most) or favicon (256 KB) is small enough to belong there. Served by
 * {@see BrandAssetController} with an immutable cache lifetime — the random part of the
 * name changes on every upload, so a cached copy can never be stale.
 *
 * A deployment with shared object storage can still bind
 * {@see ObjectStorageBrandAssetStore}, or set `whitelabel.assets.store` to `disk` for
 * {@see LocalBrandAssetStore} on a single machine with a linked public disk.
 */
final readonly class DatabaseBrandAssetStore implements BrandAssetStore
{
    /** What may be served as an image; anything else is refused at upload already. */
    public const array MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'];

    public function __construct(
        private EnvironmentContext $environment,
        private string $basePath = 'brand',
    ) {}

    public function put(string $kind, UploadedFile $file): string
    {
        $contents = $file->get();
        $mime = $file->getMimeType();

        if ($contents === false || ! is_string($mime) || ! in_array($mime, self::MIMES, true)) {
            throw new RuntimeException('Failed to store brand asset.');
        }

        $path = $this->directory().'/'.$this->filename($kind, $file);

        BrandAsset::query()->create([
            'path' => $path,
            'environment_key' => $this->environmentKey(),
            'mime' => $mime,
            'size' => strlen($contents),
            'contents' => base64_encode($contents),
        ]);

        return route('whitelabel.asset', ['path' => $path]);
    }

    public function forget(?string $url): void
    {
        $path = AssetPath::fromUrl($url, $this->basePath);

        // Only ever inside THIS environment's folder — the rule {@see LocalBrandAssetStore}
        // learned the hard way: a URL naming another environment's asset must not reach it.
        if ($path === null || ! str_starts_with($path, $this->directory().'/')) {
            return;
        }

        BrandAsset::query()
            ->whereKey($path)
            ->where('environment_key', $this->environmentKey())
            ->delete();
    }

    private function environmentKey(): string
    {
        return $this->environment->has()
            ? $this->environment->requireEnvironment()->environmentKey()
            : 'shared';
    }

    private function directory(): string
    {
        return trim($this->basePath, '/').'/'.$this->environmentKey();
    }

    private function filename(string $kind, UploadedFile $file): string
    {
        $extension = strtolower($file->extension() ?: 'bin');
        $extension = preg_match('/\A[a-z0-9]{1,5}\z/', $extension) === 1 ? $extension : 'bin';

        $slug = preg_replace('/[^a-z0-9]/', '', strtolower($kind)) ?? '';
        $slug = $slug === '' ? 'asset' : $slug;

        return $slug.'-'.bin2hex(random_bytes(8)).'.'.$extension;
    }
}
