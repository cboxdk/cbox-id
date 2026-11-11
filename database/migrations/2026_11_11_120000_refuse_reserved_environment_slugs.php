<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * No environment may hold a slug the platform's own hosts use (`api`, `app`, …): from this
 * release `api.cboxid.com` is the management API, and an environment with that slug would be
 * resolved for it. None should exist. If one does, this stops the deploy and names it, so it
 * is renamed on purpose rather than silently shadowing the API host.
 *
 * The platform root (`is_default`) is not served by its slug and is not asked.
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
                .implode(', ', array_map(static fn (string $id, string $slug): string => "{$slug} ({$id})", array_keys($taken), $taken)).'.');
        }
    }

    public function down(): void
    {
        // Nothing was changed.
    }
};
