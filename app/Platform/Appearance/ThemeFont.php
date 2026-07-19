<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

/**
 * The typefaces a customer can pick for the hosted sign-in — every option is either
 * self-hosted or a system stack, never a silently-missing webfont.
 */
enum ThemeFont: string
{
    case System = 'system';
    case Geometric = 'geometric';
    case Serif = 'serif';

    /** The CSS font-family stack this choice maps to. */
    public function stack(): string
    {
        return match ($this) {
            self::System => "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
            self::Geometric => "'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif",
            self::Serif => "ui-serif, Georgia, Cambria, 'Times New Roman', serif",
        };
    }

    /** A short human label for the editor. */
    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::Geometric => 'Geometric',
            self::Serif => 'Serif',
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
}
