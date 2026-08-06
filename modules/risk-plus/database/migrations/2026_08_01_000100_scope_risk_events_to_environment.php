<?php

declare(strict_types=1);

use Cbox\Id\RiskPlus\Models\RiskEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Risk events were written to one global table with no tenancy column.
 *
 * The console page reads `RiskEvent::query()->latest()->limit(50)`, and the model was a
 * plain Eloquent model — no `EnvironmentOwned`, no `BelongsToEnvironment` — so an
 * administrator in one environment was shown every other environment's flagged
 * sign-ins, including the IP address and the email address in the clear. On a
 * multi-tenant deployment that is one tenant reading another tenant's people.
 *
 * Existing rows cannot be attributed after the fact: nothing about them records which
 * environment produced them. They are deleted rather than parked under a guessed
 * environment or left readable by everyone — a review trail that lies about whose it is
 * is worse than an empty one, and this table is a rolling operational feed, not a
 * system of record (the tamper-evident audit chain is elsewhere and is untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Unattributable by construction; see the class docblock.
        DB::table((new RiskEvent)->getTable())->delete();

        Schema::table((new RiskEvent)->getTable(), function (Blueprint $table): void {
            $table->string('environment_id', 26)->after('id');
            $table->index(['environment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table((new RiskEvent)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['environment_id', 'created_at']);
            $table->dropColumn('environment_id');
        });
    }
};
