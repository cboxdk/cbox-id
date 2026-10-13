<?php

declare(strict_types=1);

namespace App\Platform\Passkeys;

/**
 * WHAT A PASSKEY MAY BE CALLED — one rule for every door that names one.
 *
 * A name is the person's own label for a credential ("Work laptop", "iCloud Keychain"),
 * shown back to them on My account, in the API and to an agent acting for them. It is
 * never markup and never a place for a second line: trimmed, one to sixty-four characters,
 * no control characters, and none of the invisible direction overrides that make a label
 * read differently from what it contains. Escaping is the renderer's job everywhere it is
 * drawn (React and Blade both escape); refusing these is about what the person sees.
 */
final class PasskeyName
{
    public const int MAX = 64;

    /** What a passkey is called when nothing better is known. */
    public const string FALLBACK = 'Passkey';

    /**
     * Control characters (tabs, newlines, NUL…) and the bidirectional overrides and
     * isolates (U+202A–U+202E, U+2066–U+2069) — the characters that make a name render as
     * something it does not contain.
     */
    private const string FORBIDDEN = '/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** The name, trimmed, when it is one a passkey may carry — otherwise null. */
    public static function normalize(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $trimmed = trim($name);

        return self::problem($trimmed) === null ? $trimmed : null;
    }

    /**
     * Why `$name` (already trimmed) cannot be a passkey's name, in a sentence, or null when
     * it can.
     */
    public static function problem(string $name): ?string
    {
        if ($name === '') {
            return 'Give the passkey a name.';
        }

        if (preg_match('//u', $name) !== 1) {
            return 'The name is not valid text.';
        }

        if (mb_strlen($name) > self::MAX) {
            return 'Keep the name to '.self::MAX.' characters or fewer.';
        }

        if (preg_match(self::FORBIDDEN, $name) === 1) {
            return 'The name cannot contain line breaks or other control characters.';
        }

        return null;
    }
}
