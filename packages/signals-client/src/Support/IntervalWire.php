<?php

declare(strict_types=1);

namespace Cbox\Signals\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class IntervalWire
{
    /** Preserve all source nanoseconds instead of round-tripping through PHP floats/dates. */
    public static function timestamp(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/D', $value)) {
            throw new InvalidArgumentException('Interval timestamps must use UTC RFC3339.');
        }
        Wire::date($value);

        return $value;
    }

    public static function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** Compare UTC source timestamps without PHP's microsecond truncation. */
    public static function compare(string $left, string $right): int
    {
        $key = static function (string $value): string {
            self::timestamp($value);

            return substr($value, 0, 19).str_pad(str_contains($value, '.') ? substr($value, 20, -1) : '', 9, '0');
        };

        return strcmp($key($left), $key($right));
    }

    public static function count(string $value): string
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,19})$/D', $value) || (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0)) {
            throw new InvalidArgumentException('Count must be an unsigned 64-bit decimal string.');
        }

        return $value;
    }

    public static function hash(mixed $value): string
    {
        $value = Wire::text($value);
        if (! preg_match('/^[0-9a-f]{64}$/D', $value)) {
            Wire::fail();
        }

        return $value;
    }
}
