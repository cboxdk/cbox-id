<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

/**
 * The four anchor colours of one mode (light or dark) of a hosted-sign-in theme.
 * A strongly-typed replacement for the old `array{primary, background, foreground,
 * muted}` shape — the resolver derives the full coherent token set from these.
 * Every colour is a validated `#rrggbb` hex.
 */
final readonly class ThemeMode
{
    public function __construct(
        public string $primary,
        public string $background,
        public string $foreground,
        public string $muted,
    ) {}

    /**
     * Parse a raw colour map, filling each missing/invalid value from a fallback
     * mode so the result is always complete and valid.
     *
     * @param  array<mixed>  $raw
     */
    public static function fromArray(array $raw, self $fallback): self
    {
        return new self(
            Appearance::hex($raw['primary'] ?? null) ?? $fallback->primary,
            Appearance::hex($raw['background'] ?? null) ?? $fallback->background,
            Appearance::hex($raw['foreground'] ?? null) ?? $fallback->foreground,
            Appearance::hex($raw['muted'] ?? null) ?? $fallback->muted,
        );
    }

    /** Copy this mode with a replaced primary (legacy brand_color path). */
    public function withPrimary(string $primary): self
    {
        return new self($primary, $this->background, $this->foreground, $this->muted);
    }

    /**
     * @return array{primary: string, background: string, foreground: string, muted: string}
     */
    public function toArray(): array
    {
        return [
            'primary' => $this->primary,
            'background' => $this->background,
            'foreground' => $this->foreground,
            'muted' => $this->muted,
        ];
    }
}
