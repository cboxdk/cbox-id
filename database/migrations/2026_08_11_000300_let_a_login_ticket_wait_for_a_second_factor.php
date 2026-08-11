<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket that is not yet a sign-in, because a second factor is still owed.
 *
 * The password check and the TOTP check are two requests, and a cross-origin page carries
 * no session cookie between them — so the pending state cannot live where the hosted form
 * keeps it. It travels as a token instead, which is what Auth0 calls an `mfa_token`.
 *
 * TWO STAGES ON ONE ROW rather than two tables, because they are the same fact at two
 * moments: this credential check, for this person, from this key. Splitting them would
 * mean copying the subject, the environment and the methods across, and a copy is a place
 * for them to disagree.
 *
 * `attempts` EXISTS BECAUSE SINGLE-USE IS WRONG HERE. A login ticket is spent the instant
 * it is redeemed — it crosses one redirect and a second redemption is an attack. A pending
 * ticket is different: somebody mistypes a six-digit code, and making that cost them their
 * password as well is a worse product for no security. So it survives a few wrong codes and
 * is consumed on the right one, with the count bounded so it cannot become an oracle to
 * brute-force against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frontend_login_tickets', function (Blueprint $table): void {
            // `ready` — redeemable for a session at /authorize.
            // `pending_mfa` / `pending_otp` — the password was right and a factor is owed.
            $table->string('stage', 16)->default('ready')->after('subject_id');

            $table->unsignedTinyInteger('attempts')->default(0)->after('stage');
        });
    }

    public function down(): void
    {
        Schema::table('frontend_login_tickets', function (Blueprint $table): void {
            $table->dropColumn(['stage', 'attempts']);
        });
    }
};
