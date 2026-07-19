<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;

/**
 * A validated, strongly-typed appearance for an organization's or environment's
 * hosted sign-in — a preset anchor plus a per-mode {@see ThemeMode} (light/dark), a
 * {@see ThemeRadius}, and a {@see ThemeFont}. Persisted inside {@see Organization}
 * (or {@see Environment}) settings under `appearance`;
 * rendered to CSS by {@see AppearanceCss}.
 *
 * Arrays appear ONLY at the serialization edges ({@see fromArray}/{@see toArray} for
 * stored JSON + the client editor payload); the domain model itself is typed. Every
 * value is sanitized on the way in, so what reaches a public `<style>` is always safe.
 */
final readonly class Appearance
{
    public function __construct(
        public string $preset,
        public ThemeRadius $radius,
        public ThemeFont $font,
        public ThemeMode $light,
        public ThemeMode $dark,
    ) {}

    public static function fromPreset(string $id): self
    {
        return ThemePresets::get($id)->toAppearance();
    }

    /**
     * Build from a settings bag. A full `appearance` block wins; a legacy
     * `brand_color` seeds the default preset's primary; otherwise the default preset.
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
        $legacy = self::hex($settings['brand_color'] ?? null);

        return $legacy === null
            ? $base
            : new self($base->preset, $base->radius, $base->font, $base->light->withPrimary($legacy), $base->dark->withPrimary($legacy));
    }

    /**
     * The EFFECTIVE appearance for a hosted sign-in: an organization's own theme
     * wins wholesale when it has one, else the environment's default, else null
     * (→ the platform default, no override injected).
     *
     * @param  array<string, mixed>|null  $orgSettings
     * @param  array<string, mixed>|null  $envSettings
     */
    public static function effective(?array $orgSettings, ?array $envSettings): ?self
    {
        if (is_array($orgSettings) && self::isCustomized($orgSettings)) {
            return self::fromSettings($orgSettings);
        }

        if (is_array($envSettings) && self::isCustomized($envSettings)) {
            return self::fromSettings($envSettings);
        }

        return null;
    }

    /**
     * Whether a settings bag carries a custom appearance (a full block, or the
     * legacy single `brand_color`).
     *
     * @param  array<string, mixed>  $settings
     */
    public static function isCustomized(array $settings): bool
    {
        return is_array($settings['appearance'] ?? null) || self::hex($settings['brand_color'] ?? null) !== null;
    }

    /**
     * @param  array<mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $presetId = is_string($raw['preset'] ?? null) && ThemePresets::has($raw['preset'])
            ? $raw['preset']
            : ThemePresets::default();
        $preset = ThemePresets::get($presetId);

        return new self(
            $presetId,
            ThemeRadius::fromValue(is_string($raw['radius'] ?? null) ? $raw['radius'] : null, $preset->radius),
            ThemeFont::fromValue(is_string($raw['font'] ?? null) ? $raw['font'] : null, $preset->font),
            ThemeMode::fromArray(is_array($raw['light'] ?? null) ? $raw['light'] : [], $preset->light),
            ThemeMode::fromArray(is_array($raw['dark'] ?? null) ? $raw['dark'] : [], $preset->dark),
        );
    }

    /**
     * @return array{preset: string, radius: string, font: string, light: array{primary: string, background: string, foreground: string, muted: string}, dark: array{primary: string, background: string, foreground: string, muted: string}}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'radius' => $this->radius->value,
            'font' => $this->font->value,
            'light' => $this->light->toArray(),
            'dark' => $this->dark->toArray(),
        ];
    }

    public function fontStack(): string
    {
        return $this->font->stack();
    }

    /** A #rrggbb hex (lower-cased), or null if not a valid 6-digit hex. */
    public static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : null;
    }
}
