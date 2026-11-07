<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usage rows carry their quantity, the distinct key a unique-count meter counts, and
 * dimensions. Additive: the running version keeps inserting rows that take the defaults.
 *
 * The first meter keys (`cbox-id.auth.*`) were published as totals-fed, so central
 * billing refused every event sent to them. Rows still waiting move to the event-fed
 * keys that replace them; a key with no successor stays as it is and keeps its error.
 */
return new class extends Migration
{
    private const SUCCESSORS = [
        'cbox-id.auth.session' => 'cbox-id.sign_ins',
        'cbox-id.auth.login' => 'cbox-id.sso_sign_ins',
        'cbox-id.auth.user' => 'cbox-id.users_created',
        'cbox-id.auth.identity_linked' => 'cbox-id.identity_links',
        'cbox-id.auth.organization' => 'cbox-id.organizations_created',
        'cbox-id.auth.member_added' => 'cbox-id.members_added',
        'cbox-id.auth.member_removed' => 'cbox-id.members_removed',
        'cbox-id.auth.invitation' => 'cbox-id.invitations_sent',
        'cbox-id.auth.invitation_accepted' => 'cbox-id.invitations_accepted',
        'cbox-id.auth.role_assigned' => 'cbox-id.role_assignments',
        'cbox-id.auth.service_account' => 'cbox-id.service_accounts_created',
        'cbox-id.auth.ciba' => 'cbox-id.ciba_requests',
        'cbox-id.auth.domain_verified' => 'cbox-id.domains_verified',
        'cbox-id.auth.governance_campaign' => 'cbox-id.governance_campaigns',
        'cbox-id.auth.directory_user' => 'cbox-id.directory_provisions',
    ];

    public function up(): void
    {
        Schema::table('product_billing_usage_outbox', function (Blueprint $table): void {
            $table->unsignedBigInteger('value')->default(1);
            $table->string('unique_key', 128)->nullable();
            $table->json('dimensions')->nullable();
        });

        foreach (self::SUCCESSORS as $old => $new) {
            DB::table('product_billing_usage_outbox')->whereNull('reported_at')->where('meter', $old)
                ->update(['meter' => $new, 'last_error' => null]);
        }
    }

    public function down(): void
    {
        Schema::table('product_billing_usage_outbox', function (Blueprint $table): void {
            $table->dropColumn(['value', 'unique_key', 'dimensions']);
        });
    }
};
