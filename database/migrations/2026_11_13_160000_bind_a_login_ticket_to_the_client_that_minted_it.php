<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A login ticket is redeemed only from the network address the sign-in that minted it
 * came from.
 *
 * A ticket opens a session in whichever browser presents it at /oauth/authorize. Without a
 * binding, anybody with an account could mint one for themselves from a server and send a
 * victim's browser to /authorize with it, signing that browser in as them (login CSRF).
 * The embedded sign-in runs in the person's own browser, so the mint and the redemption
 * come from the same address; a server-minted ticket replayed through somebody else's
 * browser does not.
 *
 * A keyed hash, never the address: the row only needs to compare it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frontend_login_tickets', function (Blueprint $table): void {
            $table->string('client_hash', 64)->nullable()->after('subject_id');
        });
    }

    public function down(): void
    {
        Schema::table('frontend_login_tickets', function (Blueprint $table): void {
            $table->dropColumn('client_hash');
        });
    }
};
