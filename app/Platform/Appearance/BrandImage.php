<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

/**
 * The two images a brand carries onto the hosted pages: its logo and its favicon.
 *
 * UPLOADED, NEVER LINKED. Both used to be — the logo at least — an https URL an
 * administrator typed, rendered as an `<img src>` on the sign-in page. Every visitor of
 * that page then made a request to whoever served that URL, which hands a third party
 * each visitor's address, browser and the moment they arrived at a sign-in screen: a
 * tracking pixel with a customer's logo on it. Images are now bytes we hold and serve
 * from this application's own origin (`/brand-assets/…`), so the only party that learns a
 * visitor is here is the one they came to.
 *
 * The limits live here so the console, the management API and the MCP tool refuse the
 * same thing with the same sentence. SVG is refused rather than sanitised: it is a
 * document that can carry script and external references, and a sanitiser for it is a
 * parser we would have to keep correct forever, against an input whose whole purpose is
 * to look like a logo. A PNG or WebP of any logo is a few kilobytes.
 */
enum BrandImage: string
{
    case Logo = 'logo';
    case Favicon = 'favicon';

    /** The most bytes accepted — the same limits the white-label upload form always had. */
    public function maxBytes(): int
    {
        return match ($this) {
            self::Logo => 1024 * 1024,
            self::Favicon => 256 * 1024,
        };
    }

    /**
     * The longest edge, in pixels. A logo is drawn about 36px high and a favicon at 16 to
     * 64; anything near this bound is a mistake, and an image that decodes to a gigapixel
     * canvas from a few kilobytes is an attack on every visitor's browser.
     */
    public function maxDimension(): int
    {
        return match ($this) {
            self::Logo => 4096,
            self::Favicon => 1024,
        };
    }

    /**
     * The content types accepted — sniffed from the bytes, never taken from a name or a
     * declared type.
     *
     * @return list<string>
     */
    public function mimes(): array
    {
        return match ($this) {
            self::Logo => ['image/png', 'image/jpeg', 'image/webp'],
            self::Favicon => ['image/png', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'],
        };
    }

    /** The accepted formats in words, for the sentence a refusal is made of. */
    public function formats(): string
    {
        return match ($this) {
            self::Logo => 'PNG, JPEG or WebP',
            self::Favicon => 'PNG, ICO or WebP',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Logo => 'logo',
            self::Favicon => 'favicon',
        };
    }
}
