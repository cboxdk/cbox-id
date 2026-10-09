<?php

declare(strict_types=1);

namespace App\Platform\Radar\Enums;

/**
 * What Radar does with a sign-in or a sign-up: let it through, ask for proof, or refuse it.
 *
 * Challenge is a second factor on sign-in (the authenticator when there is one, an emailed
 * code when there is not) and a CAPTCHA or an emailed code on sign-up. A passkey and a magic
 * link already prove possession of something, so a challenge on those doors is satisfied by
 * the door itself.
 */
enum RadarAction: string
{
    case Allow = 'allow';
    case Challenge = 'challenge';
    case Block = 'block';

    /** How severe, for "the strictest of the built-in rules that fired wins". */
    public function severity(): int
    {
        return match ($this) {
            self::Allow => 0,
            self::Challenge => 1,
            self::Block => 2,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $action): string => $action->value, self::cases());
    }
}
