<?php

declare(strict_types=1);

namespace App\Platform\Install\Enums;

/**
 * Something found on a platform that proves it is already in use.
 *
 * The installer refuses to run when ANY of these is present, and it reports which —
 * "already installed" is not an answer an operator can act on, and a bootstrap that
 * re-provisions or re-keys a live deployment is the failure this set exists to prevent.
 *
 * `Account` was a case of its own and is gone rather than renamed: a customer IS an
 * organization now, so it would have been a second name for the check `Organization`
 * already makes, and two cases that probe the same table is how an occupancy set starts
 * disagreeing with itself.
 */
enum PlatformOccupant: string
{
    case Operator = 'operator';
    case Environment = 'environment';
    case Subject = 'subject';
    case Organization = 'organization';
    case Client = 'client';

    /**
     * Not something found in the database — the report that the database could not be
     * read at all. It counts as occupancy because an installer that cannot see what is
     * there must not assume nothing is.
     */
    case Unreadable = 'unreadable';

    /** What was found, phrased so it can be read straight out of a refusal message. */
    public function found(): string
    {
        return match ($this) {
            self::Operator => 'a platform operator exists',
            self::Environment => 'an environment beyond a bare default exists',
            self::Subject => 'a user identity exists',
            self::Organization => 'an organization exists',
            self::Client => 'a registered OAuth client exists',
            self::Unreadable => 'the database could not be read, so what is there is unknown',
        };
    }
}
