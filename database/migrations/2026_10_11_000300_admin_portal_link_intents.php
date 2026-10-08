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
        // Added nullable and tightened once filled: a NOT NULL column cannot be added to a
        // table with rows on PostgreSQL without a default, and a default left behind is a
        // schema the old migrations never produced.
        Schema::table('admin_portal_links', function (Blueprint $table): void {
            $table->string('scope')->nullable()->after('organization_id');
        });

        // Read in PHP rather than compared in SQL: MySQL's JSON column does not equal its
        // own text, and the old column has no word for the intents it never had.
        foreach (DB::table('admin_portal_links')->get(['id', 'intents', 'expires_at']) as $row) {
            $intents = json_decode(is_string($row->intents) ? $row->intents : '[]', true);
            $intents = is_array($intents) ? $intents : [];
            $sso = in_array('sso', $intents, true);
            $audit = $intents === ['audit_logs'];
            $scim = in_array('dsync', $intents, true);
            $scope = $audit ? 'audit_logs' : ($sso && $scim ? 'both' : ($scim ? 'scim' : ($sso ? 'sso' : null)));

            $update = ['scope' => $scope ?? 'sso'];

            // A link for intents the old column cannot name (domain verification, log
            // streams, certificate renewal) would come back as an `sso` link — handing the
            // customer's administrator the single sign-on screens nobody gave them. It is
            // expired instead; whoever needs it is sent a new one.
            if ($scope === null && is_string($row->expires_at) && now()->lt($row->expires_at)) {
                $update['expires_at'] = now();
            }

            DB::table('admin_portal_links')->where('id', $row->id)->update($update);
        }

        Schema::table('admin_portal_links', function (Blueprint $table): void {
            $table->string('scope')->nullable(false)->change();
        });

        Schema::table('admin_portal_links', function (Blueprint $table): void {
            $table->dropColumn(['intents', 'emailed_to']);
        });
    }
};
