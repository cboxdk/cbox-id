<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

/**
 * The curated starting points for the hosted sign-in Theme Editor — each a complete,
 * typed {@see ThemePreset} (light AND dark {@see ThemeMode}, a {@see ThemeRadius}, a
 * {@see ThemeFont}), not a loose array. A customer picks one, then fine-tunes.
 */
final class ThemePresets
{
    /**
     * @var array<string, ThemePreset>|null
     */
    private static ?array $cache = null;

    /**
     * @return array<string, ThemePreset>
     */
    public static function all(): array
    {
        return self::$cache ??= [
            'cbox' => new ThemePreset(
                'cbox', 'Cbox', 'The signature look — a confident blue on warm neutrals.',
                ThemeRadius::Large, ThemeFont::Geometric,
                new ThemeMode('#2f62ea', '#ffffff', '#1a1714', '#6b6459'),
                new ThemeMode('#7ba0ff', '#141210', '#f2ece3', '#9a9082'),
            ),
            'midnight' => new ThemePreset(
                'midnight', 'Midnight', 'Deep indigo, built for the dark.',
                ThemeRadius::Medium, ThemeFont::System,
                new ThemeMode('#4f46e5', '#f6f6fb', '#1e1b2e', '#6b6785'),
                new ThemeMode('#8b7fff', '#0e0e18', '#e8e6f5', '#8f8ba8'),
            ),
            'minimal' => new ThemePreset(
                'minimal', 'Minimal', 'Monochrome and sharp — the type does the talking.',
                ThemeRadius::ExtraSmall, ThemeFont::System,
                new ThemeMode('#111111', '#ffffff', '#0a0a0a', '#737373'),
                new ThemeMode('#fafafa', '#0a0a0a', '#fafafa', '#a3a3a3'),
            ),
            'warm' => new ThemePreset(
                'warm', 'Warm', 'Cream and terracotta — soft, editorial, rounded.',
                ThemeRadius::ExtraLarge, ThemeFont::Serif,
                new ThemeMode('#c2582f', '#faf6f0', '#2b2018', '#8a7a68'),
                new ThemeMode('#e08a5f', '#1c1510', '#f2e8dd', '#a89684'),
            ),
            'contrast' => new ThemePreset(
                'contrast', 'Contrast', 'Maximum legibility — pure grounds, AAA-minded.',
                ThemeRadius::Small, ThemeFont::System,
                new ThemeMode('#0b53ff', '#ffffff', '#000000', '#404040'),
                new ThemeMode('#6aa8ff', '#000000', '#ffffff', '#c8c8c8'),
            ),
        ];
    }

    public static function get(string $id): ThemePreset
    {
        return self::all()[$id] ?? self::all()[self::default()];
    }

    public static function has(string $id): bool
    {
        return array_key_exists($id, self::all());
    }

    public static function default(): string
    {
        return 'cbox';
    }

    /**
     * The preset catalogue as an editor payload (serialization boundary).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function toPayload(): array
    {
        return array_map(static fn (ThemePreset $p): array => $p->toArray(), self::all());
    }
}
