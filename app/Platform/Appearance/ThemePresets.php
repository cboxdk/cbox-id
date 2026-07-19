<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

/**
 * The curated starting points for the hosted sign-in Theme Editor. Each preset is a
 * complete, considered look — a light AND dark token set, a radius, and a typeface —
 * not a random palette. A customer picks one, then fine-tunes.
 *
 * Colours are hex; the {@see AppearanceCss} resolver derives the full coherent token
 * set (soft/edge/border/ring, auto-contrast accent foreground) from these four
 * anchors per mode, so the entire sign-in surface re-themes together.
 */
final class ThemePresets
{
    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     radius: string,
     *     font: string,
     *     light: array{primary: string, background: string, foreground: string, muted: string},
     *     dark: array{primary: string, background: string, foreground: string, muted: string},
     * }>
     */
    public static function all(): array
    {
        return [
            'cbox' => [
                'label' => 'Cbox',
                'description' => 'The signature look — a confident blue on warm neutrals.',
                'radius' => '0.75rem',
                'font' => 'geometric',
                'light' => ['primary' => '#2f62ea', 'background' => '#ffffff', 'foreground' => '#1a1714', 'muted' => '#6b6459'],
                'dark' => ['primary' => '#7ba0ff', 'background' => '#141210', 'foreground' => '#f2ece3', 'muted' => '#9a9082'],
            ],
            'midnight' => [
                'label' => 'Midnight',
                'description' => 'Deep indigo, built for the dark.',
                'radius' => '0.5rem',
                'font' => 'system',
                'light' => ['primary' => '#4f46e5', 'background' => '#f6f6fb', 'foreground' => '#1e1b2e', 'muted' => '#6b6785'],
                'dark' => ['primary' => '#8b7fff', 'background' => '#0e0e18', 'foreground' => '#e8e6f5', 'muted' => '#8f8ba8'],
            ],
            'minimal' => [
                'label' => 'Minimal',
                'description' => 'Monochrome and sharp — the type does the talking.',
                'radius' => '0.25rem',
                'font' => 'system',
                'light' => ['primary' => '#111111', 'background' => '#ffffff', 'foreground' => '#0a0a0a', 'muted' => '#737373'],
                'dark' => ['primary' => '#fafafa', 'background' => '#0a0a0a', 'foreground' => '#fafafa', 'muted' => '#a3a3a3'],
            ],
            'warm' => [
                'label' => 'Warm',
                'description' => 'Cream and terracotta — soft, editorial, rounded.',
                'radius' => '1rem',
                'font' => 'serif',
                'light' => ['primary' => '#c2582f', 'background' => '#faf6f0', 'foreground' => '#2b2018', 'muted' => '#8a7a68'],
                'dark' => ['primary' => '#e08a5f', 'background' => '#1c1510', 'foreground' => '#f2e8dd', 'muted' => '#a89684'],
            ],
            'contrast' => [
                'label' => 'Contrast',
                'description' => 'Maximum legibility — pure grounds, AAA-minded.',
                'radius' => '0.375rem',
                'font' => 'system',
                'light' => ['primary' => '#0b53ff', 'background' => '#ffffff', 'foreground' => '#000000', 'muted' => '#404040'],
                'dark' => ['primary' => '#6aa8ff', 'background' => '#000000', 'foreground' => '#ffffff', 'muted' => '#c8c8c8'],
            ],
        ];
    }

    /** The font-family stacks a customer can choose — all self-hosted or system, never a silently-missing webfont. */
    public const FONTS = [
        'system' => "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
        'geometric' => "'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif",
        'serif' => "ui-serif, Georgia, Cambria, 'Times New Roman', serif",
    ];

    /** Allowed corner radii (rem). */
    public const RADII = ['0rem', '0.25rem', '0.375rem', '0.5rem', '0.75rem', '1rem'];

    public static function default(): string
    {
        return 'cbox';
    }

    public static function has(string $id): bool
    {
        return array_key_exists($id, self::all());
    }
}
