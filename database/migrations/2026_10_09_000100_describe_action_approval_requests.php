<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an action approval is FOR, so a person can approve it from the console as well as
 * from their phone.
 *
 * The row bound an approval to the credential that raised it and nothing more — the digest
 * the framework spends it against is a hash, and a hash is not something anybody can read
 * before saying yes. So the request now keeps:
 *
 *  - `environment_id`: which environment's console lists it (null for a workspace key's,
 *    which belongs to no environment);
 *  - `binding_code`: the four characters the agent shows its user and the approver sees
 *    beside Approve, so the two can be matched by eye;
 *  - `input`: a REDACTED copy of the validated input — every secret the action declares,
 *    and every field whose name says it is one, replaced before it is written. It is for
 *    reading, never for running: the run is the agent's repeat, checked against the digest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_approval_requests', function (Blueprint $table): void {
            $table->string('environment_id', 26)->nullable()->after('action');
            $table->string('binding_code', 8)->nullable()->after('environment_id');
            $table->json('input')->nullable()->after('binding_code');
            $table->index(['environment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('action_approval_requests', function (Blueprint $table): void {
            $table->dropIndex(['environment_id', 'created_at']);
            $table->dropColumn(['environment_id', 'binding_code', 'input']);
        });
    }
};
