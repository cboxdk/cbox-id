<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

use Cbox\Id\Organization\Models\Organization;

/**
 * A validated, normalized appearance for an organization's hosted sign-in — a preset
 * anchor plus per-mode colours (primary/background/foreground/muted), a corner radius,
 * and a typeface. Persisted inside {@see Organization}
 * settings under `appearance`; rendered to CSS by {@see AppearanceCss}.
 *
 * Every value is sanitized here (hex clamped, font/radius/preset allow-listed) so
 * whatever reaches the resolver — and thus a `<style>` block on a public page — is
 * always safe and well-formed, never attacker-controlled free text.
 *
 * @phpstan-type Mode array{primary: string, background: string, foreground: string, muted: string}
 */
final class Appearance
{
    /**
     * @param  Mode  $light
     * @param  Mode  $dark
     */
    public function __construct(
        public readonly string $preset,
        public readonly string $radius,
        public readonly string $font,
        public readonly array $light,
        public readonly array $dark,
    ) {}

    public static function fromPreset(string $id): self
    {
        $presets = ThemePresets::all();
        $id = ThemePresets::has($id) ? $id : ThemePresets::default();
        $p = $presets[$id];

        return new self($id, $p['radius'], $p['font'], $p['light'], $p['dark']);
    }

    /**
     * Build from an organization's stored settings. Falls back gracefully: a full
     * `appearance` block wins; a legacy `brand_color` seeds the default preset's
     * primary; otherwise the plain default preset.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function fromSettings(array $settings): self
    {
        $raw = $settings['appearance'] ?? null;

        if (is_array($raw)) {
            return self::fromArray($raw);
        }

        $base = self::fromPreset(ThemePresets::default());

        // Legacy single-colour branding → keep honouring it as the primary.
        $legacy = self::hex($settings['brand_color'] ?? null);
        if ($legacy !== null) {
            return new self(
                $base->preset,
                $base->radius,
                $base->font,
                ['primary' => $legacy] + $base->light,
                ['primary' => $legacy] + $base->dark,
            );
        }

        return $base;
    }

    /**
     * @param  array<mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $preset = is_string($raw['preset'] ?? null) && ThemePresets::has($raw['preset'])
            ? $raw['preset'] : ThemePresets::default();

        $fallback = ThemePresets::all()[$preset];

        return new self(
            $preset,
            in_array($raw['radius'] ?? null, ThemePresets::RADII, true) ? $raw['radius'] : $fallback['radius'],
            is_string($raw['font'] ?? null) && array_key_exists($raw['font'], ThemePresets::FONTS) ? $raw['font'] : $fallback['font'],
            self::mode(is_array($raw['light'] ?? null) ? $raw['light'] : [], $fallback['light']),
            self::mode(is_array($raw['dark'] ?? null) ? $raw['dark'] : [], $fallback['dark']),
        );
    }

    /**
     * @return array{preset: string, radius: string, font: string, light: Mode, dark: Mode}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'radius' => $this->radius,
            'font' => $this->font,
            'light' => $this->light,
            'dark' => $this->dark,
        ];
    }

    public function fontStack(): string
    {
        return ThemePresets::FONTS[$this->font] ?? ThemePresets::FONTS['system'];
    }

    /**
     * Sanitize one mode's four colours, filling any missing/invalid value from the
     * preset fallback so the result is always complete.
     *
     * @param  array<mixed>  $raw
     * @param  Mode  $fallback
     * @return Mode
     */
    private static function mode(array $raw, array $fallback): array
    {
        $out = [];
        foreach (['primary', 'background', 'foreground', 'muted'] as $key) {
            $out[$key] = self::hex($raw[$key] ?? null) ?? $fallback[$key];
        }

        return $out;
    }

    /** A #rrggbb hex (lower-cased), or null if not a valid 6-digit hex. */
    public static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : null;
    }
}
