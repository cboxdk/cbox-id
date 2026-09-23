<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an invitation is FOR, beside the invitation itself.
 *
 * The framework's `invitations` row says who is invited to which organization with which
 * role, and nothing more — so accepting always landed the person on this product's own
 * dashboard, never back in the app that invited them. This table carries the app's half:
 * which app (`client_id`) asked, where in it to send the person afterwards (`return_to`),
 * and the name of whoever sent it.
 *
 * An app table next to `invitation_role_grants`, keyed to the invitation the same way and
 * for the same reason: the framework table is not this application's to widen, and a
 * context keyed to anything looser than the invitation outlives the invitation that chose it.
 *
 * `return_to` is TEXT, not a sized string: a URL has no useful length limit to index on,
 * and the validator already caps it before it gets here. `invited_by_name` is copied at
 * send time because the inviter may be an environment administrator — a subject of the
 * platform root, not of the environment the invitee will read the page in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_contexts', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26)->index();
            $table->string('organization_id', 26)->index();
            $table->string('invitation_id', 26)->unique();
            $table->string('client_id')->nullable();
            $table->text('return_to')->nullable();
            $table->string('invited_by_name', 190)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_contexts');
    }
};
