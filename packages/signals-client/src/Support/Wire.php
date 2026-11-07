<?php

declare(strict_types=1);

namespace Cbox\Signals\Support;

use Cbox\Signals\Exceptions\SignalsFailure;
use DateMalformedStringException;
use DateTimeImmutable;

/** Strict response decoding; malformed success is never treated as a result. */
final class Wire
{
    /** @return array<string,mixed> */
    public static function object(mixed $value): array
    {
        if (! is_array($value)) {
            self::fail();
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                self::fail();
            }
            $result[$key] = $item;
        }

        return $result;
    }

    public static function text(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            self::fail();
        }

        return $value;
    }

    public static function integer(mixed $value, int $minimum = 0): int
    {
        if (! is_int($value) || $value < $minimum) {
            self::fail();
        }

        return $value;
    }

    public static function number(mixed $value): float
    {
        if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)) {
            self::fail();
        }

        return (float) $value;
    }

    /** @return list<mixed> */
    public static function items(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            self::fail();
        }

        return $value;
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        return array_map(self::text(...), self::items($value));
    }

    public static function date(mixed $value): DateTimeImmutable
    {
        $text = self::text($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $text)) {
            self::fail();
        }
        try {
            $date = new DateTimeImmutable($text);
        } catch (DateMalformedStringException) {
            self::fail();
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            self::fail();
        }

        return $date;
    }

    public static function fail(): never
    {
        throw new SignalsFailure('malformed_response', 503);
    }
}
