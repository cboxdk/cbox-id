<?php

declare(strict_types=1);

use App\Platform\Actions\OrganizationScopes;
use App\Platform\Actions\RenamedActions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v3: the workspace is the organization, in what is stored too.
 *
 *  - Key provenance: a key minted by an organization key records `organization_key`, in
 *    BOTH key tables — an environment key minted by one finds its approver by it
 *    (`EnvironmentKeyPrincipal::approverSubjectId()`).
 *  - The two non-chained action tables: action names (`workspace.*` → `organization.*`,
 *    `platform.*` → `admin.*`; {@see RenamedActions}) and the `workspace_key:` owner kind,
 *    so a pending approval or an idempotent answer stored before the deploy still resolves.
 *  - Organization keys' scope lists in the new names ({@see OrganizationScopes}), the one
 *    vocabulary this app owns end to end. Environment keys and OAuth grants keep theirs
 *    until laravel-id learns the new names; every check accepts both until then.
 *  - The billing usage outbox names its column for what it holds.
 *
 * NEVER the audit trails: `audit_logs` and `app_audit_events` hold the actor and the action
 * inside each hashed event, so rewriting one breaks every chain after it. They are history,
 * and the console translates the old names when it reads them.
 */
return new class extends Migration
{
    private const array KEY_TABLES = ['organization_api_keys', 'environment_api_keys'];

    private const array ACTION_TABLES = ['action_approval_requests', 'action_idempotency_records'];

    public function up(): void
    {
        $this->provenance('workspace_key', 'organization_key');
        $this->actions(RenamedActions::MAP, RenamedActions::PRINCIPAL_KINDS);
        $this->organizationKeyScopes();

        if (Schema::hasColumn('product_billing_usage_outbox', 'workspace_id')) {
            Schema::table('product_billing_usage_outbox', function (Blueprint $table): void {
                $table->renameColumn('workspace_id', 'organization_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_billing_usage_outbox', 'organization_id')) {
            Schema::table('product_billing_usage_outbox', function (Blueprint $table): void {
                $table->renameColumn('organization_id', 'workspace_id');
            });
        }

        $this->actions(array_flip(RenamedActions::MAP), array_flip(RenamedActions::PRINCIPAL_KINDS));
        $this->provenance('organization_key', 'workspace_key');
        // Scope lists stay in the new names: v2 reads neither, and a v2 key minted with
        // scopes is re-minted on rollback like any other credential v2 cannot read.
    }

    private function provenance(string $from, string $to): void
    {
        foreach (self::KEY_TABLES as $table) {
            if (Schema::hasColumn($table, 'created_by_type')) {
                DB::table($table)->where('created_by_type', $from)->update(['created_by_type' => $to]);
            }
        }
    }

    /**
     * @param  array<string, string>  $names
     * @param  array<string, string>  $kinds
     */
    private function actions(array $names, array $kinds): void
    {
        foreach (self::ACTION_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($names as $old => $new) {
                DB::table($table)->where('action', $old)->update(['action' => $new]);
            }

            foreach ($kinds as $old => $new) {
                DB::table($table)->where('principal', 'like', $old.':%')->orderBy('id')->lazyById(500, 'id')
                    ->each(function (object $row) use ($table, $old, $new): void {
                        $principal = property_exists($row, 'principal') && is_string($row->principal) ? $row->principal : '';
                        DB::table($table)->where('id', $row->id)->update(['principal' => $new.substr($principal, strlen($old))]);
                    });
            }
        }
    }

    private function organizationKeyScopes(): void
    {
        DB::table('organization_api_keys')->whereNotNull('scopes')->orderBy('id')->lazyById(500, 'id')
            ->each(function (object $row): void {
                $scopes = property_exists($row, 'scopes') && is_string($row->scopes) ? json_decode($row->scopes, true) : null;

                if (! is_array($scopes)) {
                    return;
                }

                $renamed = OrganizationScopes::normalise($scopes);

                if ($renamed !== array_values($scopes)) {
                    DB::table('organization_api_keys')->where('id', $row->id)->update(['scopes' => json_encode($renamed, JSON_THROW_ON_ERROR)]);
                }
            });
    }
};
