<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Workspace keys are `cbid_ws_` now, and the old `cbid_org_` keys stop resolving the moment
 * the prefix changes: the prefix is part of the stored hash, so nothing can carry them across.
 *
 * Marked revoked here so the console says what happened — a key that silently stopped
 * working while its row still reads "active" sends an operator looking for the wrong fault.
 * Its owner mints a `cbid_ws_` replacement on the Keys page.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('organization_api_keys')
            ->where('prefix', 'like', 'cbid_org_%')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function down(): void
    {
        // Irreversible by nature: the keys cannot resolve under the new prefix either way.
    }
};
