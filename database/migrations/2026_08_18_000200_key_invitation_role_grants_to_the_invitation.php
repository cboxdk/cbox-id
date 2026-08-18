<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a parked access-role grant to the invitation that chose it.
 *
 * The grants were keyed `(organization_id, email, role_id)` — the invitation itself was
 * nowhere in the row. Revoking an invitation updated the invitation and left the grants
 * behind, so the roles an administrator had deliberately withdrawn were still sitting
 * there waiting for the NEXT invitation to that address: invite somebody as
 * `finance-admin`, think better of it and revoke, invite them again as a plain member,
 * and they land holding `finance-admin`. Nothing in the flow ever said so.
 *
 * Backfill matches each grant to that organization's live invitation for the same
 * address. A grant that matches none is deleted rather than carried forward with a null
 * — a parked role with no invitation behind it is precisely the stale grant this fixes,
 * and keeping it would migrate the bug into the new shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('invitation_role_grants', 'invitation_id')) {
            return;
        }

        Schema::table('invitation_role_grants', function (Blueprint $table): void {
            $table->string('invitation_id', 26)->nullable()->after('organization_id')->index();
        });

        // Pending only: an accepted invitation's grants were consumed and deleted at
        // acceptance, and a revoked or expired one's should never be applied again.
        DB::table('invitation_role_grants')->orderBy('id')->chunkById(500, function ($grants): void {
            foreach ($grants as $grant) {
                $invitationId = DB::table('invitations')
                    ->where('organization_id', $grant->organization_id)
                    ->where('email', $grant->email)
                    ->where('status', 'pending')
                    ->orderByDesc('created_at')
                    ->value('id');

                if ($invitationId === null) {
                    DB::table('invitation_role_grants')->where('id', $grant->id)->delete();

                    continue;
                }

                DB::table('invitation_role_grants')
                    ->where('id', $grant->id)
                    ->update(['invitation_id' => $invitationId]);
            }
        });

        Schema::table('invitation_role_grants', function (Blueprint $table): void {
            // The old key let one address accumulate a grant per role across every
            // invitation it was ever sent. The new one scopes uniqueness to the
            // invitation, which is what "the roles chosen for THIS invite" means.
            $table->dropUnique(['organization_id', 'email', 'role_id']);
            $table->unique(['invitation_id', 'role_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('invitation_role_grants', 'invitation_id')) {
            return;
        }

        Schema::table('invitation_role_grants', function (Blueprint $table): void {
            $table->dropUnique(['invitation_id', 'role_id']);
            $table->unique(['organization_id', 'email', 'role_id']);
            $table->dropColumn('invitation_id');
        });
    }
};
