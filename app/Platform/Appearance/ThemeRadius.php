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
