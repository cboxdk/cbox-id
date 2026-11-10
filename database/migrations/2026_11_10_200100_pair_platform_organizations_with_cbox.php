<?php

declare(strict_types=1);

use App\Platform\Organizations\OrganizationLinks;
use Illuminate\Database\Migrations\Migration;

/**
 * Pair every platform-root organization with the `cbox` organization of the same slug.
 *
 * Only an exact slug match is paired, and only when an active owner of the platform-root
 * organization is an active member of the `cbox` one (two unrelated customers can both
 * have chosen `acme`), neither side is paired yet, and the slug is not claimed by another
 * platform-root organization's address ({@see OrganizationLinks::autoPairs()}).
 * `orgs:link --by-slug --dry-run` lists what this pairs; `orgs:unlink` undoes a mistake. Everything else is left for an operator:
 * `php artisan orgs:link --unpaired` lists it and `orgs:link {platform} {cbox}` pairs it.
 * An unpaired organization keeps working under its own slug, so nothing here can lock
 * anybody out, and nothing is written outside the new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(OrganizationLinks::class)->pairBySlug();
    }

    public function down(): void
    {
        // The table goes with the migration before this one.
    }
};
