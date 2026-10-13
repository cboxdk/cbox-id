<?php

declare(strict_types=1);

use App\Platform\SignInLineage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIGN-IN LINEAGE — which platform session an environment console session was handed off
 * from.
 *
 * An environment's admin console runs on the environment's own host, with its own cookie
 * and its own platform-root session row, minted by redeeming a handoff from the account
 * host. Nothing tied the two rows together, so signing out on either host ended that host's
 * row only, and the other one opened the console again in one redirect. One row here per
 * (console session, account-host session it was minted from); signing out on any host
 * ends the whole family ({@see SignInLineage}).
 *
 * Not environment-owned: both ids name rows in the platform root, and the lookup runs on
 * whichever host the person signs out on. Rows are bounded by the deployment's session
 * lifetime, past which every session they name has expired anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_lineage', function (Blueprint $table): void {
            // The console session minted by the handoff.
            $table->string('session_id', 26);
            // A session held by the browser that minted the handoff.
            $table->string('parent_session_id', 26);
            $table->timestamp('created_at');

            $table->primary(['session_id', 'parent_session_id']);
            $table->index('parent_session_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_lineage');
    }
};
