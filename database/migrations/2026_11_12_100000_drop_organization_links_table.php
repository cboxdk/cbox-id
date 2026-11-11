<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `organization_links` joined a platform-root organization to its copy in the `cbox`
 * environment. With one realm an organization exists once and the table has nothing to
 * say.
 *
 * REFUSES TO RUN WHILE THE TABLE HOLDS A ROW. A row means the two realms are still two on
 * this deployment: `cbox-id:realm:flip` empties the table as its last step, and it was
 * removed from the code in the same release as this migration. Deploy the release before
 * this one, run the flip, then deploy this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_links')) {
            return;
        }

        if (DB::table('organization_links')->exists()) {
            throw new RuntimeException(
                'organization_links still holds rows: this deployment has not been flipped to one realm. '
                .'Deploy the previous release, run `php artisan cbox-id:realm:flip`, and deploy again.',
            );
        }

        Schema::drop('organization_links');
    }

    public function down(): void
    {
        // The pairing is gone with the second realm; there is nothing to bring back.
    }
};
