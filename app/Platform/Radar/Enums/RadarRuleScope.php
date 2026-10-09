<?php

declare(strict_types=1);

namespace App\Platform\Radar\Enums;

/**
 * Which flows a rule is evaluated on.
 */
enum RadarRuleScope: string
{
    case All = 'all';
    case SignIn = 'sign_in';
    case SignUp = 'sign_up';

    public function covers(RadarFlow $flow): bool
    {
        return match ($this) {
            self::All => true,
            self::SignIn => $flow === RadarFlow::SignIn,
            self::SignUp => $flow === RadarFlow::SignUp,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }
}
