<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Every time on the management API is ISO 8601 in UTC, or null — one spelling, so a client
 * parses one format rather than whatever each model's cast happened to produce.
 */
final class Timestamp
{
    public static function of(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
