<?php

declare(strict_types=1);

use App\Platform\Passkeys\RecordsPasskeyUse;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHEN A PASSKEY LAST SIGNED SOMEBODY IN.
 *
 * My account lists passkeys so a person can tell which is which and remove the one they
 * no longer have. "Added 3 March" answers the first half; "last used 2 days ago" answers
 * the second — the passkey on the phone that went in the drawer is the one nobody has
 * used since. The framework's table has the name and the dates of the row, and `updated_at`
 * is no stand-in: a synced passkey reports a signature counter of zero, so an assertion
 * leaves the row unchanged and its timestamp untouched.
 *
 * Stamped by the app around the framework's own assertion check
 * ({@see RecordsPasskeyUse}), on every door a passkey signs in at.
 *
 * ADDED HERE, on the framework's table, the way this repo already widens others: the
 * column is additive and nullable, so the framework's own reads and writes are unaffected,
 * and a framework release that adds the same column later is met by the guard below
 * rather than by a duplicate-column failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webauthn_credentials') || Schema::hasColumn('webauthn_credentials', 'last_used_at')) {
            return;
        }

        Schema::table('webauthn_credentials', function (Blueprint $table): void {
            $table->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('webauthn_credentials', 'last_used_at')) {
            Schema::table('webauthn_credentials', function (Blueprint $table): void {
                $table->dropColumn('last_used_at');
            });
        }
    }
};
