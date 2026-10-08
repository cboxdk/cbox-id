<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An Admin Portal link can be WITHDRAWN, and says when its setup was FINISHED.
 *
 * A link is the whole credential for configuring how an organization signs in, and it is
 * handed to somebody outside — by mail, often to an address typed in a hurry. Until now
 * nothing could take one back: it worked until it expired or was opened, and once opened its
 * setup session ran its full window whatever happened. `revoked_at` is the withdrawal, and
 * redemption and every request of an open setup session ask it.
 *
 * `completed_at` is the moment the IT administrator pressed Finish. It was on the audit
 * trail (`portal_link.completed`) and nowhere a list of links could read it; `consumed_at`
 * is the redemption moment, which is not the same thing.
 *
 * ADDITIVE and nullable: every existing link is neither revoked nor known to be finished,
 * which is exactly what null says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_portal_links', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable()->after('consumed_at');
            $table->timestamp('revoked_at')->nullable()->after('completed_at');
            $table->string('revoked_by')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('admin_portal_links', function (Blueprint $table): void {
            $table->dropColumn(['completed_at', 'revoked_at', 'revoked_by']);
        });
    }
};
