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
     * The nearest shade of a brand colour that is legible AS TEXT on a given ground.
     *
     * A brand colour is chosen to look right as a filled button, where it carries white
     * or near-black on top of it. Used the other way round — coloured text on the page
     * ground — a mid-tone blue that is perfect on a button can sit at 3:1, which fails
     * AA for body text. That is not hypothetical: the platform's own dark accent
     * measured 3.08:1 on the card and 2.82:1 on its soft fill, and every "View activity
     * log"-style link in dark mode was rendered in it.
     *
     * Rather than force a second colour on the tenant, this walks the SAME hue toward
     * the ground's opposite in small steps and stops at the first shade clearing
     * `$target`. A tenant's blue stays their blue; it just becomes a readable one. If
     * even the extreme is short of the target — a ground with nowhere left to go — the
     * most legible shade found is returned, because unreadable-but-honest beats
     * substituting a colour they never chose.
     */
    public static function readableOn(string $color, string $background, float $target = 4.5): string
    {
        if (self::contrastRatio($color, $background) >= $target) {
            return $color;
        }

        // Toward white on a dark ground, toward black on a light one.
        $toward = self::relativeLuminance($background) < 0.5 ? 1.0 : 0.0;

        [$r, $g, $b] = self::channels($color);
        $best = $color;
        $bestRatio = self::contrastRatio($color, $background);

        for ($step = 1; $step <= 20; $step++) {
            $t = $step / 20;
            $candidate = self::hex(
                $r + ($toward - $r) * $t,
                $g + ($toward - $g) * $t,
                $b + ($toward - $b) * $t,
            );

            $ratio = self::contrastRatio($candidate, $background);

            if ($ratio > $bestRatio) {
                $best = $candidate;
                $bestRatio = $ratio;
            }

            if ($ratio >= $target) {
                return $candidate;
            }
        }

        return $best;
    }

    private static function hex(float $r, float $g, float $b): string
    {
        $channel = static fn (float $c): string => str_pad(
            dechex((int) round(max(0.0, min(1.0, $c)) * 255)),
            2,
            '0',
            STR_PAD_LEFT,
        );

        return '#'.$channel($r).$channel($g).$channel($b);
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
