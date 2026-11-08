<?php

declare(strict_types=1);

namespace App\SignalsData;

use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Models\Environment;

/**
 * Which environments capture a Signals source feed. `SIGNALS_DATA_ENVIRONMENTS`
 * lists environment ids, or `*` for every active environment, so a new
 * environment joins the bundled integration without a configuration change.
 */
final class SourceEnvironments
{
    public static function everyActive(): bool
    {
        return in_array('*', config()->array('signals-data.environments'), true);
    }

    public static function enabled(): bool
    {
        return self::configured() !== [] || self::everyActive();
    }

    public static function includes(string $environment): bool
    {
        if (in_array($environment, self::configured(), true)) {
            return true;
        }

        return self::everyActive() && Environment::query()->whereKey($environment)->where('status', EnvironmentStatus::Active)->exists();
    }

    /** @return list<string> */
    public static function listed(): array
    {
        return self::everyActive() ? array_keys(self::active()) : self::configured();
    }

    /** @return list<string> */
    private static function configured(): array
    {
        return array_values(array_filter(config()->array('signals-data.environments'), static fn (mixed $id): bool => is_string($id) && $id !== '' && $id !== '*'));
    }

    /** @return array<string, true> */
    private static function active(): array
    {
        $ids = Environment::query()->where('status', EnvironmentStatus::Active)->orderBy('id')->pluck('id')->all();

        return array_fill_keys(array_values(array_filter($ids, is_string(...))), true);
    }
}
