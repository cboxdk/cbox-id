<?php

declare(strict_types=1);

namespace App\Platform\Radar\Enums;

/**
 * The credential an attempt is made with. A passkey and a magic link prove possession on
 * their own, which is why a challenge does not add a step to them.
 */
enum RadarMethod: string
{
    case Password = 'password';
    case MagicLink = 'magic_link';
    case Passkey = 'passkey';
    case SignUp = 'sign_up';

    /** Whether the door itself already proves possession of a second thing. */
    public function provesPossession(): bool
    {
        return $this === self::MagicLink || $this === self::Passkey;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $method): string => $method->value, self::cases());
    }
}
