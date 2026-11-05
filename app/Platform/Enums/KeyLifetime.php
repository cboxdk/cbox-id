<?php

declare(strict_types=1);

namespace App\Platform\Enums;

use App\Platform\Locale\ConsoleStrings;
use Carbon\CarbonImmutable;

/**
 * How long a new machine credential lives — the choice the key forms offer.
 *
 * Both key services have always accepted an expiry and neither form asked for one, so
 * every key minted in the console lived forever. A bounded lifetime is the cheapest
 * control there is against a key that leaked into a CI log two years ago, and the one
 * people only reach for when the form puts it in front of them.
 *
 * NEVER STAYS THE DEFAULT. Changing a default under somebody's automation is how a
 * provisioning job stops at 03:00 on a date nobody chose; the form offers the bounded
 * lifetimes first and the reader picks.
 *
 * A CUSTOM DATE ENDS AT THE END OF THAT DAY, in UTC. "Expires on 1 March" read by a person
 * means the key still works on 1 March; midnight at the start of it would retire it a
 * day before the date they typed.
 */
enum KeyLifetime: string
{
    case Never = 'never';
    case Days30 = '30';
    case Days90 = '90';
    case Days365 = '365';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Never => __('console.never_expires_6a989420'),
            self::Days30 => ConsoleStrings::message('console.30_days_ffd72805'),
            self::Days90 => ConsoleStrings::message('console.90_days_38825b0f'),
            self::Days365 => ConsoleStrings::message('console.1_year_91647bad'),
            self::Custom => __('console.custom_date_43bce053'),
        };
    }

    /**
     * The moment a key minted now stops working, or null for a key that does not expire.
     *
     * `$on` is the custom date and is read only for {@see self::Custom}; validation is what
     * guarantees it is present and in the future when that case is chosen.
     */
    public function expiresAt(CarbonImmutable $now, ?CarbonImmutable $on = null): ?CarbonImmutable
    {
        return match ($this) {
            self::Never => null,
            self::Days30 => $now->addDays(30),
            self::Days90 => $now->addDays(90),
            self::Days365 => $now->addDays(365),
            self::Custom => $on?->utc()->endOfDay(),
        };
    }
}
