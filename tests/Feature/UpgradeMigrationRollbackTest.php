<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rolling back from 2.0.0 must hand 1.1.x the schema its own migrations produced.
 *
 * Found by the upgrade rehearsal (scripts/upgrade-path-check.sh): after
 * `migrate:rollback` on MySQL 8.4 and PostgreSQL 17, the schema differed from a fresh
 * 1.1.1 install in two places — `onboarding_dismissals.organization_id` stayed nullable,
 * and `admin_portal_links.scope` came back with a `default 'sso'` it never had. The
 * rehearsal diffs the whole schema on real engines; these pin the two `down()`s on the
 * suite's own run.
 *
 * Each test runs `down()` and then `up()` again, so the schema is the current one for
 * every test after it — whatever the engine does with DDL inside the test's transaction.
 */
function upgradeMigration(string $name): Migration
{
    return require database_path("migrations/{$name}.php");
}

/** @return array{nullable: bool, default: mixed} */
function upgradeColumn(string $table, string $column): array
{
    $found = collect(Schema::getColumns($table))->firstWhere('name', $column);

    expect($found)->not->toBeNull();

    return ['nullable' => (bool) $found['nullable'], 'default' => $found['default']];
}

it('rolls onboarding dismissals back to every row naming an organization', function (): void {
    $migration = upgradeMigration('2026_10_10_000100_environment_onboarding_dismissals');

    DB::table('onboarding_dismissals')->insert([
        ['id' => 'od_org', 'environment_id' => 'env_test', 'organization_id' => 'org_1', 'subject_id' => 'sub_1', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 'od_env', 'environment_id' => 'env_test', 'organization_id' => null, 'subject_id' => 'sub_1', 'created_at' => now(), 'updated_at' => now()],
    ]);

    try {
        $migration->down();

        // The environment checklist's dismissal has no place in the old schema; the
        // organization's stays, and the column is NOT NULL again.
        expect(DB::table('onboarding_dismissals')->pluck('id')->all())->toBe(['od_org'])
            ->and(upgradeColumn('onboarding_dismissals', 'organization_id')['nullable'])->toBeFalse();
    } finally {
        $migration->up();
        DB::table('onboarding_dismissals')->whereIn('id', ['od_org', 'od_env'])->delete();
    }

    expect(upgradeColumn('onboarding_dismissals', 'organization_id')['nullable'])->toBeTrue();
});

it('rolls portal link intents back to the scope column exactly, and expires what it cannot name', function (): void {
    $migration = upgradeMigration('2026_10_11_000300_admin_portal_link_intents');

    $link = fn (string $id, string $intents): array => [
        'id' => $id,
        'environment_id' => 'env_test',
        'organization_id' => 'org_1',
        'intents' => $intents,
        'token_hash' => hash('sha256', $id),
        'expires_at' => now()->addMinutes(30),
        'created_by' => 'sub_1',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    DB::table('admin_portal_links')->insert([
        $link('apl_sso', '["sso"]'),
        $link('apl_dsync', '["dsync"]'),
        $link('apl_both', '["sso","dsync"]'),
        $link('apl_audit', '["audit_logs"]'),
        $link('apl_logs', '["log_streams"]'),
    ]);

    try {
        $migration->down();

        expect(DB::table('admin_portal_links')->orderBy('id')->pluck('scope', 'id')->all())->toBe([
            'apl_audit' => 'audit_logs',
            'apl_both' => 'both',
            'apl_dsync' => 'scim',
            'apl_logs' => 'sso',
            'apl_sso' => 'sso',
        ])
            // NOT NULL and no default: what `create_admin_portal_links_table` declared.
            ->and(upgradeColumn('admin_portal_links', 'scope'))->toBe(['nullable' => false, 'default' => null]);

        // A log-streams link cannot be expressed as a scope; rather than come back as an
        // SSO link it is expired. The others keep their lifetime.
        $expiry = DB::table('admin_portal_links')->pluck('expires_at', 'id');
        expect(now()->gte((string) $expiry['apl_logs']))->toBeTrue()
            ->and(now()->lt((string) $expiry['apl_sso']))->toBeTrue();
    } finally {
        $migration->up();
        DB::table('admin_portal_links')->where('id', 'like', 'apl\_%')->delete();
    }

    expect(Schema::hasColumn('admin_portal_links', 'scope'))->toBeFalse()
        ->and(Schema::hasColumn('admin_portal_links', 'intents'))->toBeTrue();
});
