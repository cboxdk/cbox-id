<?php

declare(strict_types=1);

namespace App\SignalsData;

use Illuminate\Support\Facades\DB;

final class SourcePrivacyLock
{
    /** @param list<string> $hashes */
    public static function acquire(string $environment, array $hashes, bool $erasing): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        sort($hashes, SORT_STRING);
        $function = $erasing ? 'pg_advisory_xact_lock' : 'pg_advisory_xact_lock_shared';
        foreach (array_unique($hashes) as $hash) {
            DB::select('SELECT '.$function.'(hashtextextended(?, 0))', ['id-source/'.$environment.'/'.$hash]);
        }
    }
}
