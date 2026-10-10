<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

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
            throw new InvalidBrandImage($kind, self::lead($kind).'Send the image itself as a base64 data: URI ('
                .'data:image/png;base64,…). Remote image URLs are not accepted: an image on a sign-in page is fetched by every visitor, and a URL elsewhere would tell its host who they are.');
        }

        if (str_contains(strtolower($match[1]), 'svg')) {
            throw self::svg($kind);
        }

        $bytes = base64_decode(substr($uri, strlen($match[0])), true);

        if ($bytes === false || $bytes === '') {
            throw new InvalidBrandImage($kind, self::lead($kind).'The data: URI is not valid base64.');
        }

        return self::fromBytes($kind, $bytes);
    }

    /**
     * @throws InvalidBrandImage
     */
    public static function fromBytes(BrandImage $kind, string $bytes): self
    {
        if (strlen($bytes) > $kind->maxBytes()) {
            throw new InvalidBrandImage($kind, self::lead($kind).'It is larger than '.self::size($kind->maxBytes()).'.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $mime = is_string($mime) ? $mime : '';

        if (str_contains($mime, 'svg') || preg_match('/\A\s*(<\?xml|<svg|<!DOCTYPE svg)/i', $bytes) === 1) {
            throw self::svg($kind);
        }

        if (! in_array($mime, $kind->mimes(), true)) {
            throw new InvalidBrandImage($kind, self::lead($kind).'Use a '.$kind->formats().' image.');
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new InvalidBrandImage($kind, self::lead($kind).'The file is not a readable image.');
        }

        if (max($size[0], $size[1]) > $kind->maxDimension()) {
            throw new InvalidBrandImage($kind, self::lead($kind).'Keep it under '.$kind->maxDimension().' pixels on its longest side.');
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
        return new InvalidBrandImage($kind, self::lead($kind).'SVG is not accepted — it can carry a script. Use a '.$kind->formats().' image.');
    }

    private static function lead(BrandImage $kind): string
    {
        return 'That '.$kind->label().' cannot be used. ';
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? ($bytes / 1024 / 1024).' MB' : ($bytes / 1024).' KB';
    }
}
