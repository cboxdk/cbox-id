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
     * Whether this mode's own text is readable on its own background.
     *
     * `hex()` validates the FORMAT and nothing else, so `#101014` was a perfectly
     * acceptable "light" background — and a tenant who set one got their near-white
     * foreground on it, or worse, on the platform's beige where a token was not emitted.
     * Their end users then could not read the sign-in form. WCAG 1.4.3 body text is the
     * bar because that is what these two colours are: the page's words on the page.
     *
     * Muted text is checked at the large-text floor: it is deliberately quieter, and
     * holding it to 4.5 would refuse every restrained palette worth having.
     *
     * @return list<string> a sentence per failing pair; empty when the mode is legible
     */
    public function contrastFailures(): array
    {
        $failures = [];
        $body = Color::contrastRatio($this->foreground, $this->background);
        $muted = Color::contrastRatio($this->muted, $this->background);

        if ($body < 4.5) {
            $failures[] = sprintf('Text on the background is %.2f:1 — it needs 4.5:1 to be readable.', $body);
        }

        if ($muted < 3.0) {
            $failures[] = sprintf('Muted text on the background is %.2f:1 — it needs 3:1.', $muted);
        }

        return $failures;
    }

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
