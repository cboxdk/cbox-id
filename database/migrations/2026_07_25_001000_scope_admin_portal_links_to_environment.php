<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Environment-scope the Admin Portal setup links.
 *
 * `admin_portal_links` was the app's only credential-bearing table without an
 * `environment_id` — its sibling app table (`invitation_role_grants`) has had one
 * since it was created, and every package table that carries a redeemable secret is
 * environment-owned. The omission made the redemption route a cross-environment
 * primitive: `AdminPortal::redeem()` matched on `token_hash` alone, so a link minted
 * on one environment's host was redeemable on ANY host, and the SSO connections,
 * verified domains and SCIM directories the redeemer then created were stamped with
 * whatever environment the redeeming host resolved to.
 *
 * The backfill derives each link's environment from its organization via a
 * correlated subquery — portable across sqlite/mysql/pgsql, same shape as
 * laravel-id's `2026_07_25_000800_scope_invitations_to_environment`.
 *
 * ADDITIVE and nullable: the column is added and populated before any code reads it,
 * so old pods keep serving during a rolling deploy. Rows whose organization no longer
 * exists stay null and are unreachable through the env-scoped model — the correct
 * outcome for a setup link into a deleted org.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_portal_links', function (Blueprint $table): void {
            $table->string('environment_id', 26)->nullable()->after('id')->index();
        });

        DB::statement(<<<'SQL'
            UPDATE admin_portal_links
            SET environment_id = (
                SELECT organizations.environment_id
                FROM organizations
                WHERE organizations.id = admin_portal_links.organization_id
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('admin_portal_links', function (Blueprint $table): void {
            $table->dropIndex(['environment_id']);
            $table->dropColumn('environment_id');
        });
    }
};
