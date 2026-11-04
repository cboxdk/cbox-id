<?php

declare(strict_types=1);

namespace App\Platform\Locale;

/** Translate registered UI labels at rendering time, never identifiers or user data. */
final class ConsoleStrings
{
    /** @param array<string, string|int|float> $replace */
    public static function message(string $key, array $replace = []): string
    {
        $translated = __($key, $replace);

        return is_string($translated) ? $translated : $key;
    }

    public static function key(string $text): string
    {
        $name = substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($text)), '_'), 0, 44);

        return 'console.'.$name.'_'.substr(hash('sha256', $text), 0, 8);
    }

    public static function text(string $text): string
    {
        $key = self::key($text);
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : $text;
    }
}
