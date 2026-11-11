<?php

declare(strict_types=1);

namespace App\Platform\Deprecations;

use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Support\Facades\Log;

/**
 * A caller used a name v3 retired: count it, so the alias can be removed once nobody has
 * for seven days.
 *
 * One counter, `cbox_id_retired_name_uses_total`, labelled by the surface the name came in
 * through and the retired name itself — both are a closed set of our own names, never a
 * caller's input, so the label cardinality stays bounded. The log line says who, for the
 * conversation with whoever still uses it.
 */
final class RetiredNameUse
{
    public const string METRIC = 'cbox_id_retired_name_uses_total';

    /**
     * @param  string  $surface  where the name came in: `mcp_tool`, …
     * @param  array<string, scalar|null>  $context  who used it, for the log only
     */
    public static function record(string $surface, string $retired, string $current, array $context = []): void
    {
        Telemetry::counter(self::METRIC, 'Uses of a name v3 retired, by surface and name.')
            ->inc(1, ['surface' => $surface, 'name' => $retired]);

        Log::notice("A retired name was used: {$retired} (now {$current}).", ['surface' => $surface, ...$context]);
    }
}
