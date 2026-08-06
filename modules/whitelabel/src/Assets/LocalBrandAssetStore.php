<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Assets;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * The default {@see BrandAssetStore}: writes to a Laravel filesystem disk (the
 * `public` disk by default), so there is no hard object-storage dependency. Assets
 * are namespaced per environment, with a random filename so one tenant can never
 * guess or overwrite another's.
 */
class LocalBrandAssetStore implements BrandAssetStore
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

        return $this->urlFor($path);
    }

    public function forget(?string $url): void
    {
        $path = AssetPath::fromUrl($url, $this->basePath);

        if ($path === null) {
            return;
        }

        // Only ever inside THIS environment's folder.
        //
        // `AssetPath` refuses traversal and anchors on the base folder — but the base is
        // `brand/`, while writes land in `brand/{environment}/`. So a URL naming another
        // environment's asset resolved cleanly and was deleted. Reachable because the
        // page's `logoUrl` is a plain public property: read a victim environment's logo
        // URL off its public sign-in page, set the property to it, save, then upload a
        // legitimate file — the store dutifully forgets the path it was handed.
        //
        // The property is `#[Locked]` now as well; this is the half that holds even if a
        // future caller passes a URL from somewhere else.
        if (! str_starts_with($path, $this->directory().'/')) {
            return;
        }

        if ($this->disk->exists($path)) {
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

    /**
     * The public URL for a stored asset.
     *
     * Typed as `Filesystem` rather than `Cloud` because that is what the container can
     * actually hand over: telemetry's filesystem instrumentation replaces the manager and
     * returns a decorator that implements `Filesystem` and forwards `url()` through
     * `__call` — without declaring `Cloud`. A constructor demanding `Cloud` therefore
     * TypeError'd on every resolution in any deployment with telemetry enabled, which is
     * the default, and took the whole branding page with it.
     *
     * The requirement is still real, so it is checked here and refused with a sentence
     * someone can act on, rather than asserted by a type that the runtime cannot satisfy.
     */
    private function urlFor(string $path): string
    {
        if (! $this->disk instanceof Cloud) {
            throw new RuntimeException(
                'The brand asset disk must expose public URLs (Illuminate\\Contracts\\Filesystem\\Cloud). '
                .'Configure whitelabel.assets.disk to a disk with a url, such as the public disk.'
            );
        }

        return $this->disk->url($path);
    }
}
