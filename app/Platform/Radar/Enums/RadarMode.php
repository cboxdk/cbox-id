<?php

declare(strict_types=1);

namespace App\Platform\Radar\Enums;

/**
 * Whether an environment's verdicts are acted on.
 *
 * Monitor records every verdict exactly as enforce would have made it — with `enforced`
 * false on the row — and lets the attempt through. That is the point of it: an environment
 * reads what enforcement WOULD have done to its real traffic before it does it.
 */
enum RadarMode: string
{
    case Monitor = 'monitor';
    case Enforce = 'enforce';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
