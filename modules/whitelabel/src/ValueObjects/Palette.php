<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\ValueObjects;

use Cbox\Id\Whitelabel\Support\PaletteTokens;

/**
 * A tenant's validated palette: brandable token => colour.
 *
 * The column is `json` because a palette is a small open map and that is a genuine
 * serialization boundary — but the PHP side is NOT an array. Every value that reaches
 * here has passed {@see PaletteTokens}: only the five brandable tokens survive, and
 * only hex or oklch colours. Constructing one is therefore the validation, so nothing
 * downstream has to re-check before a colour reaches the shell's `<style>` tag.
 *
 * Unknown tokens and rejected colours are DROPPED rather than raising: a palette is
 * cosmetic, and a bad value should cost that one token its override, not the request.
 */
readonly class Palette
{
    /** @param array<string, string> $colors token (unprefixed) => normalized colour */
    private function __construct(public array $colors) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Build from any raw shape — a decoded json column, or form input.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $colors = [];

        foreach (PaletteTokens::TOKENS as $token) {
            $value = $raw[$token] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $normalized = PaletteTokens::normalizeColor($value);

            if ($normalized !== null) {
                $colors[$token] = $normalized;
            }
        }

        return new self($colors);
    }

    /** The colour set for a token, or null when the tenant did not override it. */
    public function get(string $token): ?string
    {
        return $this->colors[$token] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->colors === [];
    }

    /**
     * The CSS custom properties the stylesheet reads (`--primary` => colour).
     *
     * @return array<string, string>
     */
    public function cssTokens(): array
    {
        return PaletteTokens::normalize($this->colors);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->colors;
    }
}
