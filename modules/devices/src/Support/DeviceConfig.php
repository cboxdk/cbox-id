<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

/**
 * Typed reads of this module's config.
 *
 * `config()` returns mixed, and the suite runs phpstan at level max with no baseline,
 * so every read has to be narrowed somewhere. Doing it once here rather than at each
 * call site also means a config file edited to a wrong type degrades to the documented
 * default instead of a TypeError at the point of use — which for a value like
 * `max_attempts` would surface inside a queue worker rather than at boot.
 */
final class DeviceConfig
{
    public static function bool(string $key, bool $default): bool
    {
        $value = config($key, $default);

        return is_bool($value) ? $value : $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    public static function strings(string $key): array
    {
        $value = config($key, []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $value),
            static fn (string $v): bool => $v !== '',
        ));
    }

    /**
     * A nullable string config value — used for the queue name and connection, where
     * "unset" and "empty string" must both mean "use the framework default".
     */
    public static function nullableString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
