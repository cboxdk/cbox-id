<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

/**
 * A device `user_code` the way a person actually types it — and the way the server stores
 * it, `BCDF-GHJK`.
 *
 * The code is shown in capitals with a dash on a TV across the room, and typed on a phone
 * keyboard that lower-cases the first letter, offers a space instead of a dash, or drops the
 * dash entirely. Every one of those is the same code. RFC 8628 §6.1 says as much: the
 * verification page should be forgiving about case and punctuation, because the code's
 * whole job is to survive being copied by a human.
 */
final class DeviceUserCode
{
    /** The length of a code without its dash — two groups of four. */
    private const LENGTH = 8;

    /** The canonical `XXXX-XXXX`, or null for something that cannot be a code at all. */
    public static function normalize(string $typed): ?string
    {
        $letters = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($typed)) ?? '';

        if ($letters === '' || strlen($letters) > 16) {
            return null;
        }

        return strlen($letters) === self::LENGTH
            ? substr($letters, 0, 4).'-'.substr($letters, 4)
            // Not the platform's shape — passed on as typed, upper-cased, so a code from a
            // differently-configured issuer still has its one chance to resolve.
            : mb_strtoupper(trim($typed));
    }
}
