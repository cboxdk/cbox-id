<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Branding;

use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use App\Platform\Appearance\BrandImageUpload;
use Cbox\Id\Whitelabel\Assets\BrandAssetStore;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\Models\BrandProfile;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * The host's {@see BrandImages} socket, answered from this module's brand profiles: the
 * logo and favicon are the profile's `logo_url` / `favicon_url`, written by the
 * {@see BrandAssetStore} the deployment configured (the database by default, served at
 * `/brand-assets/…` on every host).
 *
 * ONE LOGO PER ALTITUDE. The profile already had both images as uploads, and the hosted
 * sign-in drew a different one — an https URL typed on the Appearance page — so the logo a
 * customer uploaded was stored and shown nowhere, while the one that was shown leaked every
 * visitor to whoever hosted it. The Appearance page now uploads into this profile, and
 * every page that draws a logo reads it from here.
 */
final readonly class ProfileBrandImages implements BrandImages
{
    /** The path every database-stored asset is served under — see routes/whitelabel.php. */
    private const SERVED_PREFIX = '/brand-assets/';

    /**
     * @param  list<string>  $origins  origins other than this app's that serve stored images
     */
    public function __construct(
        private BrandProfiles $profiles,
        private BrandAssetStore $assets,
        private array $origins = [],
    ) {}

    public function accepting(): bool
    {
        return true;
    }

    public function url(BrandImage $kind, ?string $organizationId): ?string
    {
        $stored = $this->stored($kind, $this->profile($organizationId));

        if ($stored === null) {
            return null;
        }

        // Served by this application: the PATH, so it resolves on whichever host the page
        // is on. Anything else is the deployment's own CDN or bucket, drawn as stored.
        $path = parse_url($stored, PHP_URL_PATH);

        return is_string($path) && str_starts_with($path, self::SERVED_PREFIX) ? $path : $stored;
    }

    public function absoluteUrl(BrandImage $kind, ?string $organizationId): ?string
    {
        $url = $this->url($kind, $organizationId);

        return $url !== null && str_starts_with($url, '/') ? url($url) : $url;
    }

    public function store(BrandImageUpload $upload, ?string $organizationId): void
    {
        $profile = $this->profile($organizationId) ?? new BrandProfile(['organization_id' => $organizationId]);
        $previous = $this->stored($upload->kind, $profile);

        $profile->{$this->column($upload->kind)} = $this->assets->put($upload->kind->value, $this->file($upload));
        $this->profiles->save($profile);

        // After the save, so a failed write never leaves the profile pointing at nothing.
        $this->assets->forget($previous);
    }

    public function remove(BrandImage $kind, ?string $organizationId): void
    {
        $profile = $this->profile($organizationId);
        $previous = $this->stored($kind, $profile);

        if ($profile === null || $previous === null) {
            return;
        }

        $profile->{$this->column($kind)} = null;
        $this->profiles->save($profile);
        $this->assets->forget($previous);
    }

    public function origins(): array
    {
        return $this->origins;
    }

    private function profile(?string $organizationId): ?BrandProfile
    {
        return $organizationId === null
            ? $this->profiles->forEnvironment()
            : $this->profiles->forOrganization($organizationId);
    }

    private function stored(BrandImage $kind, ?BrandProfile $profile): ?string
    {
        $value = $profile?->{$this->column($kind)};

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function column(BrandImage $kind): string
    {
        return $kind === BrandImage::Logo ? 'logo_url' : 'favicon_url';
    }

    /**
     * The checked bytes as the file the asset store takes. The store's contract is an
     * upload because the console form used to hand it one; a temporary file is the honest
     * adapter, and it is gone when the request ends.
     */
    private function file(BrandImageUpload $upload): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'brand');

        if ($path === false || file_put_contents($path, $upload->bytes) === false) {
            throw new RuntimeException('Could not stage the brand image.');
        }

        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return new UploadedFile($path, $upload->kind->value.'.'.$upload->extension, $upload->mime, null, true);
    }
}
