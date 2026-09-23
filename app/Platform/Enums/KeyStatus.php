<?php

declare(strict_types=1);

namespace App\Platform\Enums;

use DateTimeInterface;

/**
 * Where a machine credential stands — the one fact a key list exists to answer.
 *
 * THREE STATES, NOT A BOOLEAN. The account key page drew "revoked" for every key that was
 * not active, so a key that had simply reached its expiry date was reported as somebody
 * having pulled it, and the environment key page drew no state at all: a revoked key sat
 * in the list beside the live ones with a Revoke button on it. What an administrator
 * does next is different for each — nothing for a revoked key, create a successor for an
 * expired one — so the list has to say which.
 *
 * Revocation wins over expiry: a key that was revoked and has since passed its date was
 * still stopped by a person, and that is the more useful thing to read.
 */
enum KeyStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public static function of(?DateTimeInterface $revokedAt, ?DateTimeInterface $expiresAt, DateTimeInterface $now): self
    {
        if ($revokedAt !== null) {
            return self::Revoked;
        }

        if ($expiresAt !== null && $expiresAt <= $now) {
            return self::Expired;
        }

        return self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
        };
    }
}
