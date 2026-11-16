<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An organization's owner and administrators reach every environment it owns.
 *
 * The model keeps that from now on (`Membership::saving` in AppServiceProvider); this sets
 * it on the administrators an earlier environment-access edit restricted. Their grant rows
 * stay, unread while the flag is set, so nothing is lost if one becomes a member again.
 * Not reversed: which administrators were restricted is not recorded anywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('memberships')
            ->whereIn('role', ['owner', 'admin'])
            ->where('all_environments', false)
            ->update(['all_environments' => true]);
    }

    public function down(): void {}
};
