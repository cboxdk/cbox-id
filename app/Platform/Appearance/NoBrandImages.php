<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

/**
 * {@see BrandImages} with nothing behind it — an install without the white-label module.
 * Nothing is stored, so nothing is drawn; an upload is refused with the reason rather than
 * accepted and dropped.
 */
final class NoBrandImages implements BrandImages
{
    public function accepting(): bool
    {
        return false;
    }

    public function url(BrandImage $kind, ?string $organizationId): ?string
    {
        return null;
    }

    public function absoluteUrl(BrandImage $kind, ?string $organizationId): ?string
    {
        return null;
    }

    public function store(BrandImageUpload $upload, ?string $organizationId): void
    {
        throw new InvalidBrandImage($upload->kind, 'Image uploads are not available on this install: the white-label module that stores them is not enabled.');
    }

    public function remove(BrandImage $kind, ?string $organizationId): void {}

    public function origins(): array
    {
        return [];
    }
}
