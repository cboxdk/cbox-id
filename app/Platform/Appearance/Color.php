<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

/**
 * Small, dependency-free colour maths for the Theme Editor: WCAG relative luminance
 * and contrast ratio (for the live AA readout and auto-picking a readable foreground
 * on the accent). Inputs are #rrggbb hex; behaviour is undefined for other formats,
 * so callers pass values already sanitized by {@see Appearance::hex}.
 */
final class Color
{
    /**
     * WCAG 2.x relative luminance (0 = black, 1 = white).
     *
     * @see https://www.w3.org/TR/WCAG20/#relativeluminancedef
     */
    public static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::channels($hex);

        $lin = static fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /** WCAG contrast ratio between two colours (1–21). */
    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::relativeLuminance($a);
        $lb = self::relativeLuminance($b);
        $light = max($la, $lb);
        $dark = min($la, $lb);

        return ($light + 0.05) / ($dark + 0.05);
    }

    /** A readable text colour to sit ON the given background (near-black or white). */
    public static function readableForeground(string $background): string
    {
        return self::contrastRatio($background, '#ffffff') >= self::contrastRatio($background, '#151515')
            ? '#ffffff'
            : '#151515';
    }

    /**
     * @return array{float, float, float} normalized 0–1 R,G,B
     */
    private static function channels(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }
}
