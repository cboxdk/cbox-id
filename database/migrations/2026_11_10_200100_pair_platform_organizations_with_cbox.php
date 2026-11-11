<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * This paired every platform-root organization with the `cbox` organization of the same
 * slug, in `organization_links`. The two realms have since become one
 * (`cbox-id:realm:flip`) and the table is dropped by
 * `2026_11_12_100000_drop_organization_links_table`, so on a deployment that has not run
 * this yet there is nothing left to pair. Kept, empty, so the migration history of the
 * deployments that did run it still names a file.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
