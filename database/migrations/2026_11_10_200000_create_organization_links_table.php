<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE ORGANIZATION, TWO ROWS, ONE LINK.
 *
 * A Cbox customer is an organization in the `cbox` environment: Work, Billing and every
 * other Cbox app address it at `/orgs/{slug}`. Cbox ID's console has run on a row of its
 * own in the platform-root environment (projects, keys, billing and the team hang off it).
 * This table says which platform-root row IS which `cbox` organization, so ID's console
 * addresses the organization by the same slug the other apps use.
 *
 * Each side at most once: a platform-root organization is one Cbox organization, and a
 * Cbox organization has one platform-root row. Additive: the running v2 never reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_links', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            // The platform-root organization: the row ID's console authority hangs off.
            $table->string('organization_id', 26)->unique();
            // The organization in the `cbox` environment that it is.
            $table->string('cbox_organization_id', 26)->unique();
            $table->string('cbox_environment_id', 26)->index();
            // How the pair was made: `slug` (the migration matched equal slugs) or
            // `operator` (paired by hand with `orgs:link`).
            $table->string('paired_by', 16);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('cbox_organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_links');
    }
};
