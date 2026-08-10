<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

/** The corner radii a customer can choose for the hosted sign-in (as CSS rem). */
enum ThemeRadius: string
{
    case None = '0rem';
    case ExtraSmall = '0.25rem';
    case Small = '0.375rem';
    case Medium = '0.5rem';
    case Large = '0.75rem';
    case ExtraLarge = '1rem';

    /**
     * The whole three-step scale, derived from this one choice.
     *
     * THE STYLESHEET HAS THREE RADII AND THE THEME OVERRODE ONE. `--radius` dresses cards
     * and dialogs; `--radius-md` dresses buttons and inputs; `--radius-sm` dresses badges
     * and chips. Only the first was emitted, so choosing "Square" squared the cards and
     * left every button, field and badge at their fixed 8px and 6px — the setting looked
     * broken because it half-worked, which is worse than not working at all.
     *
     * Proportional rather than a second set of choices: a person picking corners is
     * expressing one intention, and asking them to pick three that agree is a way of
     * shipping the inconsistency instead of the fix. Two-thirds and one-half, floored at
     * zero, is the ratio the hand-tuned defaults already used (12 / 8 / 6).
     *
     * @return array{radius: string, md: string, sm: string}
     */
    public function scale(): array
    {
        $rem = $this->toFloat();

        return [
            'radius' => $this->value,
            'md' => self::rem($rem * 2 / 3),
            'sm' => self::rem($rem / 2),
        ];
    }

    /** Trim a computed rem to something a stylesheet reads cleanly. */
    private static function rem(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.').'rem';
    }

    /** The numeric rem this case carries, for deriving the scale. */
    public function toFloat(): float
    {
        return (float) rtrim($this->value, 'rem');
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Square',
            self::ExtraSmall => 'XS',
            self::Small => 'S',
            self::Medium => 'M',
            self::Large => 'L',
            self::ExtraLarge => 'XL',
        };
    }

    public static function fromValue(?string $value, self $fallback = self::Large): self
    {
        return $value !== null ? (self::tryFrom($value) ?? $fallback) : $fallback;
    }

    /**
     * The allowed radius values, for the client editor payload (a boundary).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $radius): string => $radius->value, self::cases());
    }

    /**
     * value→label map for the client editor payload (a serialization boundary).
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $out = [];
        foreach (self::cases() as $radius) {
            $out[$radius->value] = $radius->label();
        }

        return $out;
    }
}
