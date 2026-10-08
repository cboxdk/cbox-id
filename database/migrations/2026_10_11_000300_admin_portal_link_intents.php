<?php

declare(strict_types=1);

use App\Platform\Enums\PortalScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An Admin Portal link covers a SET of intents now — single sign-on, directory sync,
 * domain verification, log streams, SAML certificate renewal — rather than one of `sso`,
 * `scim` or `both` ({@see PortalScope}).
 *
 * The old values are carried across exactly: `sso` becomes `["sso"]`, `scim` the directory
 * sync intent it always meant, `both` the two of them. A link minted before this runs opens
 * the same screens after it. The `scope` column goes; nothing reads it any more, and a
 * column that disagrees with `intents` would be a second answer to the one question the
 * portal asks on every request.
 *
 * And the address the link was mailed to, when it was: the console offers to send it to
 * the customer's IT contact, and the trail and the link both say to whom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_portal_links', function (Blueprint $table): void {
            $table->json('intents')->nullable()->after('organization_id');
            $table->string('emailed_to')->nullable()->after('created_by');
        });

        foreach (['sso' => '["sso"]', 'scim' => '["dsync"]', 'both' => '["sso","dsync"]', 'audit_logs' => '["audit_logs"]'] as $scope => $intents) {
            DB::table('admin_portal_links')->where('scope', $scope)->update(['intents' => $intents]);
        }

        Schema::table('admin_portal_links', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
    }

    public function down(): void
    {
        Schema::table('admin_portal_links', function (Blueprint $table): void {
            $table->string('scope')->default('sso')->after('organization_id');
        });

        // Read in PHP rather than compared in SQL: MySQL's JSON column does not equal its
        // own text, and the old column has no word for the intents it never had.
        foreach (DB::table('admin_portal_links')->get(['id', 'intents']) as $row) {
            $intents = json_decode(is_string($row->intents) ? $row->intents : '[]', true);
            $intents = is_array($intents) ? $intents : [];
            $sso = in_array('sso', $intents, true);
            $audit = $intents === ['audit_logs'];
            $scim = in_array('dsync', $intents, true);

            DB::table('admin_portal_links')->where('id', $row->id)->update([
                'scope' => $audit ? 'audit_logs' : ($sso && $scim ? 'both' : ($scim ? 'scim' : 'sso')),
            ]);
        }

        Schema::table('admin_portal_links', function (Blueprint $table): void {
            $table->dropColumn(['intents', 'emailed_to']);
        });
    }
};
