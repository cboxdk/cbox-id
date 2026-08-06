<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrolment codes that have been spent.
 *
 * The code itself is a short-lived signed JWT and is NOT stored — it is verifiable from
 * the platform's own signing keys, so keeping a copy would add a credential to the
 * database without adding a check. What is stored is the `jti` of each code that has
 * already enrolled a handset, which is the one property a signature cannot express:
 * signatures are infinitely replayable, and a code lifted off a screen photograph must
 * work exactly once.
 *
 * `jti` is the primary key, so a second enrolment with the same code is refused by the
 * DATABASE rather than by a read-then-write that two concurrent requests could both
 * pass. That race is not theoretical here: the losing path is "a second handset silently
 * enrolled on someone else's account".
 *
 * `expires_at` exists only so the table can be swept. A row is worthless once the code
 * it names could no longer be accepted anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_enrolment_codes', function (Blueprint $table): void {
            $table->string('jti', 64)->primary();
            $table->string('environment_id', 26)->index();
            $table->string('subject_id', 26);

            // Kept for the audit trail: which handset spent this code. Not a foreign
            // key — the device may be removed later, and losing the record of how it
            // arrived would be the wrong thing to lose.
            $table->string('install_id', 26)->nullable();

            $table->timestamp('consumed_at');
            $table->timestamp('expires_at');

            $table->index('expires_at', 'id_enrolment_codes_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_enrolment_codes');
    }
};
