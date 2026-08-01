<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit-export bookkeeping was shared across every environment.
 *
 * `audit_export_cursors.scope` carried a GLOBAL unique, and the system trail's scope is
 * the literal `__system__` — so one environment's export advanced a cursor another
 * environment then read. The second export resumes from a position it never reached and
 * SKIPS its own audit entries: rows that were never shipped to the customer's SIEM, and
 * a run history mixing environments in a compliance surface.
 *
 * Cursors are re-derivable (the next export re-reads from the recorded position, and a
 * reset position simply re-ships), so they are truncated rather than guessed at; run
 * history is not attributable after the fact and goes with them. Both are operational
 * bookkeeping — the tamper-evident audit chain they describe is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('audit_export_cursors')->delete();
        DB::table('audit_export_runs')->delete();

        Schema::table('audit_export_cursors', function (Blueprint $table): void {
            $table->dropUnique(['scope']);
            $table->string('environment_id', 26)->after('id');
            $table->unique(['environment_id', 'scope']);
        });

        Schema::table('audit_export_runs', function (Blueprint $table): void {
            $table->string('environment_id', 26)->after('id');
            $table->index(['environment_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_export_cursors', function (Blueprint $table): void {
            $table->dropUnique(['environment_id', 'scope']);
            $table->dropColumn('environment_id');
            $table->unique(['scope']);
        });

        Schema::table('audit_export_runs', function (Blueprint $table): void {
            $table->dropIndex(['environment_id', 'started_at']);
            $table->dropColumn('environment_id');
        });
    }
};
