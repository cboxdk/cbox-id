<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * `login`, `admin` and `www` join the slugs no environment may hold: `login.cboxid.com` is
 * the realm's sign-in host, and the other two would pass for the platform's own. The first
 * check ran with the list as it was then (`api`, `app`), so the list is asked again here.
 * If an environment holds one, this stops the deploy and names it, so it is renamed on
 * purpose.
 *
 * The platform root (`is_default`) is not served by its slug and is not asked. The
 * deployment's extra names (`reserved_environment_slugs_extra`) are not asked either: they
 * are reserved while an environment still holds one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $reserved = config('cbox-id.reserved_environment_slugs', []);

        if (! is_array($reserved) || $reserved === []) {
            return;
        }

        $taken = DB::table('environments')
            ->whereIn('slug', $reserved)
            ->where('is_default', false)
            ->pluck('slug', 'id')
            ->all();

        if ($taken !== []) {
            throw new RuntimeException('These environments use a slug reserved for the platform\'s own hosts; rename them first: '
                .implode(', ', array_map(static fn (int|string $id, mixed $slug): string => (is_string($slug) ? $slug : '?')." ({$id})", array_keys($taken), $taken)).'.');
        }
    }

    public function down(): void
    {
        // Nothing was changed.
    }
};
