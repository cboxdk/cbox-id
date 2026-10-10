<?php

declare(strict_types=1);

namespace App\Platform\Radar\Enums;

/**
 * How a condition compares a fact with the value a rule names.
 *
 * Deliberately a closed, small set: a rule is data an administrator (or an agent holding a
 * key) writes, and it is evaluated on every sign-in. Nothing here can call code, reach the
 * network, or take time proportional to anything but the handful of values a rule lists.
 */
enum RadarOperator: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case In = 'in';
    case NotIn = 'not_in';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case InCidr = 'in_cidr';
    case NotInCidr = 'not_in_cidr';

    /** Whether the operator takes a LIST of values (`values`) rather than one (`value`). */
    public function takesList(): bool
    {
        return in_array($this, [self::In, self::NotIn, self::InCidr, self::NotInCidr], true);
    }

    /** How a person reads it — the console's rendering of a condition. */
    public function phrase(): string
    {
        return match ($this) {
            self::Equals => 'is',
            self::NotEquals => 'is not',
            self::In => 'is one of',
            self::NotIn => 'is not one of',
            self::GreaterThan => '>',
            self::GreaterThanOrEqual => '≥',
            self::LessThan => '<',
            self::LessThanOrEqual => '≤',
            self::Contains => 'contains',
            self::NotContains => 'does not contain',
            self::StartsWith => 'starts with',
            self::EndsWith => 'ends with',
            self::InCidr => 'is in range',
            self::NotInCidr => 'is not in range',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $operator): string => $operator->value, self::cases());
    }
}
