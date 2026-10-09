<?php

declare(strict_types=1);

namespace App\Platform\Radar\Enums;

/**
 * The two lists. A deny entry blocks before anything else is asked; an allow entry lets an
 * attempt through without consulting a rule. Both on one attempt: deny wins.
 */
enum RadarList: string
{
    case Allow = 'allow';
    case Deny = 'deny';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $list): string => $list->value, self::cases());
    }
}
