<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

/**
 * The typefaces a customer can pick for the hosted sign-in.
 *
 * EVERY OPTION RENDERS, ON EVERY MACHINE. The list used to offer "Geometric" (the one
 * self-hosted face), "System" and "Serif" — and Serif was `ui-serif, Georgia, …`, which is
 * whatever serif the visitor's operating system happens to ship, or none at all on a
 * Linux desktop without Georgia. Worse, the choice only ever reached BODY text: headings
 * are drawn in `--font-display`, which nothing overrode, so picking a typeface changed the
 * paragraph under the heading and left the heading itself in Plus Jakarta Sans. On the
 * editor's preview it changed nothing at all (see `ThemeEditor`).
 *
 * Each face below is a self-hosted variable woff2 in `public/fonts`, latin + latin-ext,
 * declared in `resources/css/app.css` — served from this origin, so the strict
 * `font-src 'self'` holds and no visitor's address is handed to a font CDN on the way to
 * a sign-in page (a hosted font service logs every request; a sign-in page is the last
 * place to leak who is about to sign in where). All are SIL Open Font License 1.1, which
 * permits bundling and redistribution; the licence texts sit beside the files.
 *
 * The choice sets BOTH `--font-sans` and `--font-display` ({@see AppearanceCss}), so the
 * whole page — headings included — is in the face the administrator picked.
 *
 * The string values are stored in settings, so `geometric` and `serif` keep their old
 * names even though Serif is now a real, bundled face rather than a system fallback.
 */
enum ThemeFont: string
{
    case System = 'system';
    case Inter = 'inter';
    case Geometric = 'geometric';
    case Rounded = 'rounded';
    case Serif = 'serif';

    /** The CSS font-family stack this choice maps to — the family first, then fallbacks. */
    public function stack(): string
    {
        return match ($this) {
            self::System => "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
            self::Inter => "'Inter', ui-sans-serif, system-ui, sans-serif",
            self::Geometric => "'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif",
            self::Rounded => "'Nunito', ui-rounded, ui-sans-serif, system-ui, sans-serif",
            self::Serif => "'Source Serif 4', ui-serif, Georgia, Cambria, 'Times New Roman', serif",
        };
    }

    /** A short human label for the editor: the face's own name, which is what people compare. */
    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::Inter => 'Inter',
            self::Geometric => 'Plus Jakarta Sans',
            self::Rounded => 'Nunito',
            self::Serif => 'Source Serif',
        };
    }

    public static function fromValue(?string $value, self $fallback = self::System): self
    {
        return $value !== null ? (self::tryFrom($value) ?? $fallback) : $fallback;
    }

    /**
     * The value→stack map, for the client editor payload (a serialization boundary).
     *
     * @return array<string, string>
     */
    public static function stacks(): array
    {
        $out = [];
        foreach (self::cases() as $font) {
            $out[$font->value] = $font->stack();
        }

        return $out;
    }

    /**
     * The value→label map, so the editor names each face the way the server does rather
     * than keeping a second, drifting copy of the list.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $out = [];
        foreach (self::cases() as $font) {
            $out[$font->value] = $font->label();
        }

        return $out;
    }
}
