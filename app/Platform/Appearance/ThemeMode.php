<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

use App\Platform\Actions\ActionMessage;

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
        return array_map(static fn (ActionMessage $message): string => $message->render('en'), $this->contrastMessages());
    }

    /** @return list<ActionMessage> */
    public function contrastMessages(?bool $dark = null): array
    {
        $failures = [];
        $body = Color::contrastRatio($this->foreground, $this->background);
        $muted = Color::contrastRatio($this->muted, $this->background);

        if ($body < 4.5) {
            $failures[] = new ActionMessage(match ($dark) {
                true => 'console.dark_mode_text_on_the_background_is_ratio_1_it_needs_4_b765ecdf',
                false => 'console.light_mode_text_on_the_background_is_ratio_1_it_needs_4_22bc83f1',
                null => 'console.text_on_the_background_is_ratio_1_it_needs_4_5_1_to_be_34891948',
            }, ['ratio' => sprintf('%.2f', $body)]);
        }

        if ($muted < 3.0) {
            $failures[] = new ActionMessage(match ($dark) {
                true => 'console.dark_mode_muted_text_on_the_background_is_ratio_1_it_ne_85b2ff6c',
                false => 'console.light_mode_muted_text_on_the_background_is_ratio_1_it_n_7f4cfb27',
                null => 'console.muted_text_on_the_background_is_ratio_1_it_needs_3_1_6ac86f49',
            }, ['ratio' => sprintf('%.2f', $muted)]);
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
