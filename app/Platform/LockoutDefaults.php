<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\DatabaseLoginAttempts;

/**
 * The deployment's login-lockout default, as the sign-in rules page has to describe it.
 *
 * SINCE LARAVEL-ID 1.22 AN EMPTY THRESHOLD IS NOT "OFF". When no environment or
 * organization policy names one, `cbox-id.lockout.threshold` applies — ten failures inside
 * fifteen minutes lock the account for fifteen minutes — and only `CBOX_ID_LOCKOUT_THRESHOLD=0`
 * switches it off for the whole deployment. The page used to say "leave empty to disable
 * lockout", which became untrue on the upgrade; an administrator who believes lockout is
 * off when it is on gets support tickets, and one who believes it is on when the operator
 * turned it off gets a guessing run.
 *
 * Read with the same leniency {@see DatabaseLoginAttempts} reads it with, which is private
 * there: a value from an env var that is not a positive integer means "no default" for the
 * threshold, and the built-in fifteen minutes for the two durations.
 */
final class LockoutDefaults
{
    private const int DEFAULT_MINUTES = 15;

    /** The default threshold, or null when the deployment switched the default off. */
    public static function threshold(): ?int
    {
        $value = self::positive(config('cbox-id.lockout.threshold'));

        return $value > 0 ? $value : null;
    }

    public static function windowMinutes(): int
    {
        return self::positive(config('cbox-id.lockout.window_minutes')) ?: self::DEFAULT_MINUTES;
    }

    public static function durationMinutes(): int
    {
        return self::positive(config('cbox-id.lockout.duration_minutes')) ?: self::DEFAULT_MINUTES;
    }

    /**
     * @return array{threshold: int|null, windowMinutes: int, durationMinutes: int}
     */
    public static function props(): array
    {
        return [
            'threshold' => self::threshold(),
            'windowMinutes' => self::windowMinutes(),
            'durationMinutes' => self::durationMinutes(),
        ];
    }

    private static function positive(mixed $configured): int
    {
        $value = match (true) {
            is_int($configured) => $configured,
            is_string($configured) && ctype_digit($configured) => (int) $configured,
            default => 0,
        };

        return max(0, $value);
    }
}
