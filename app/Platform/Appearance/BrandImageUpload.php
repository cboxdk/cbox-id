<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

use App\Platform\Actions\ActionMessage;
use finfo;

/**
 * An uploaded logo or favicon that has been CHECKED — the only way bytes reach the asset
 * store from the console, the management API or an MCP tool.
 *
 * The API and MCP carry the image as a base64 `data:` URI (`data:image/png;base64,…`),
 * because a JSON body cannot carry a file and an MCP tool call cannot send multipart. The
 * console sends the same thing: the editor already reads the chosen file as a data URI to
 * preview it, so one encoding serves all three doors and one checker refuses for all of
 * them.
 *
 * WHAT THE BYTES ARE decides everything, not what they are called. The declared type in
 * the URI is ignored; the content is sniffed, and then decoded as an image to read its
 * dimensions — a file that sniffs as PNG but does not decode is refused. SVG is refused by
 * name so the person who tried one is told why rather than "unsupported format".
 */
final readonly class BrandImageUpload
{
    private function __construct(
        public BrandImage $kind,
        public string $bytes,
        public string $mime,
        public string $extension,
    ) {}

    /**
     * @throws InvalidBrandImage
     */
    public static function fromDataUri(BrandImage $kind, string $uri): self
    {
        $uri = trim($uri);

        if (preg_match('/\Adata:([a-z0-9.+\/-]*)(;[a-z0-9=._-]+)*;base64,/i', $uri, $match) !== 1) {
            throw new InvalidBrandImage($kind, match ($kind) {
                BrandImage::Logo => new ActionMessage('console.that_logo_cannot_be_used_send_the_image_itself_as_a_bas_ae6a22d5'), BrandImage::Favicon => new ActionMessage('console.that_favicon_cannot_be_used_send_the_image_itself_as_a_96450fb1'),
            });
        }

        if (str_contains(strtolower($match[1]), 'svg')) {
            throw self::svg($kind);
        }

        $bytes = base64_decode(substr($uri, strlen($match[0])), true);

        if ($bytes === false || $bytes === '') {
            throw new InvalidBrandImage($kind, match ($kind) {
                BrandImage::Logo => new ActionMessage('console.that_logo_cannot_be_used_the_data_uri_is_not_valid_base_a64017f3'), BrandImage::Favicon => new ActionMessage('console.that_favicon_cannot_be_used_the_data_uri_is_not_valid_b_99e6cdbc'),
            });
        }

        return self::fromBytes($kind, $bytes);
    }

    /**
     * @throws InvalidBrandImage
     */
    public static function fromBytes(BrandImage $kind, string $bytes): self
    {
        if (strlen($bytes) > $kind->maxBytes()) {
            throw new InvalidBrandImage($kind, match ($kind) {
                BrandImage::Logo => new ActionMessage('console.that_logo_cannot_be_used_it_is_larger_than_size_540f7cd1', ['size' => self::size($kind->maxBytes())]), BrandImage::Favicon => new ActionMessage('console.that_favicon_cannot_be_used_it_is_larger_than_size_2333d472', ['size' => self::size($kind->maxBytes())]),
            });
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $mime = is_string($mime) ? $mime : '';

        if (str_contains($mime, 'svg') || preg_match('/\A\s*(<\?xml|<svg|<!DOCTYPE svg)/i', $bytes) === 1) {
            throw self::svg($kind);
        }

        if (! in_array($mime, $kind->mimes(), true)) {
            throw new InvalidBrandImage($kind, match ($kind) {
                BrandImage::Logo => new ActionMessage('console.that_logo_cannot_be_used_use_a_png_jpeg_or_webp_image_0d5e8608'), BrandImage::Favicon => new ActionMessage('console.that_favicon_cannot_be_used_use_a_png_ico_or_webp_image_7fd49989'),
            });
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new InvalidBrandImage($kind, match ($kind) {
                BrandImage::Logo => new ActionMessage('console.that_logo_cannot_be_used_the_file_is_not_a_readable_ima_c5c52023'), BrandImage::Favicon => new ActionMessage('console.that_favicon_cannot_be_used_the_file_is_not_a_readable_0f0d3f67'),
            });
        }

        if (max($size[0], $size[1]) > $kind->maxDimension()) {
            throw new InvalidBrandImage($kind, match ($kind) {
                BrandImage::Logo => new ActionMessage('console.that_logo_cannot_be_used_keep_it_under_pixels_pixels_on_4e195f5d', ['pixels' => $kind->maxDimension()]), BrandImage::Favicon => new ActionMessage('console.that_favicon_cannot_be_used_keep_it_under_pixels_pixels_43e8b882', ['pixels' => $kind->maxDimension()]),
            });
        }

        return new self($kind, $bytes, $mime, match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'ico',
        });
    }

    /** The image as a data URI again — for a preview that has not been stored. */
    public function dataUri(): string
    {
        return 'data:'.$this->mime.';base64,'.base64_encode($this->bytes);
    }

    private static function svg(BrandImage $kind): InvalidBrandImage
    {
        return new InvalidBrandImage($kind, match ($kind) {
            BrandImage::Logo => new ActionMessage('console.that_logo_cannot_be_used_svg_is_not_accepted_it_can_car_4affcd99'), BrandImage::Favicon => new ActionMessage('console.that_favicon_cannot_be_used_svg_is_not_accepted_it_can_7a4eba69'),
        });
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? ($bytes / 1024 / 1024).' MB' : ($bytes / 1024).' KB';
    }
}
