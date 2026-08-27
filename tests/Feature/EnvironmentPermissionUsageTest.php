<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

    // The permissions page lives under `/admin`, which exists only on a multi-tenant
    // deployment ({@see \App\Http\Middleware\RequireMultiTenant}). It did not matter
    // while these were driven at the component; every read is a request now, and without
    // this each one answers 404 rather than rendering the page under measurement.
    multiTenantDeployment();
});

/**
 * Provision an environment WITHOUT pinning a session, so a second one can be created
 * to stand in for "another tenant".
 *
 * @return array{env: string, member: object}
 */
function usageEnvironment(string $name, string $email): array
{
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: $name,
        ownerEmail: $email,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    return ['env' => $result->environment->id, 'member' => $result->membership, 'subjectId' => $result->owner->id, 'environment' => $result->environment];
}

/** Bind a permission to a role by writing the pivot directly, as the role editor does. */
function bindPermission(string $roleId, string $permissionId): void
{
    DB::table('role_permission')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
}

/** Create a role INSIDE its own environment — Role writes are hard-scoped. */
function roleIn(string $environmentId, string $name): Role
{
    return app(EnvironmentContext::class)->runAs(
        GenericEnvironment::of($environmentId),
        fn (): Role => Role::query()->create(['environment_id' => $environmentId, 'name' => $name]),
    );
}

it('counts a permission\'s usage without loading the whole platform-wide pivot', function (): void {
    platformRootEnvironment();
    $acme = usageEnvironment('Acme', 'owner@acme.example');

    serveOnTestHost($acme['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($acme['env']));
    actAsEnvironmentAdmin($acme['subjectId'], $acme['env']);

    $permission = Permission::query()->create([
        'client_id' => null, 'environment_id' => $acme['env'], 'name' => 'reports:read', 'tenant_assignable' => true,
    ]);

    foreach (['reviewer', 'auditor'] as $roleName) {
        bindPermission(app(Roles::class)->define(null, $roleName)->id, $permission->id);
    }

    // Noise: 200 pivot rows belonging to roles this page must never count OR read.
    $otherEnvironment = (string) Str::ulid();
    $otherRole = roleIn($otherEnvironment, 'elsewhere');
    foreach (range(1, 200) as $i) {
        $noise = Permission::query()->create([
            'client_id' => null, 'environment_id' => (string) Str::ulid(), 'name' => 'noise:'.$i, 'tenant_assignable' => true,
        ]);
        bindPermission($otherRole->id, $noise->id);
    }

    /** @var list<string> $pivotReads */
    $pivotReads = [];
    DB::listen(function ($query) use (&$pivotReads): void {
        if (str_contains($query->sql, 'role_permission') && str_starts_with(strtolower(trim($query->sql)), 'select')) {
            $pivotReads[] = $query->sql;
        }
    });

    test()->get(route('environment.permissions'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'mine',
            fn (Collection $rows): bool => $rows
                ->firstWhere('name', 'reports:read')['roleCount'] === 2,
        ));

    expect($pivotReads)->not->toBeEmpty();

    foreach ($pivotReads as $sql) {
        // Aggregated in SQL, joined to `roles` so the count is this environment's, and
        // constrained to the permissions actually on the page. The version this
        // replaced was a bare `select permission_id from role_permission` with no
        // WHERE at all — every row of a table with no `environment_id`, pulled into
        // PHP and grouped there, on every render and every Livewire action.
        // Identifier quoting is per-engine — `"roles"` on sqlite/PostgreSQL,
        // `` `roles` `` on MySQL — so strip the quoting before matching rather than
        // asserting one engine's dialect. What matters is the SHAPE of the query,
        // and asserting it in sqlite's spelling made this fail on the engine the app
        // actually deploys to while the query itself was correct.
        $bare = str_replace(['"', '`'], '', $sql);

        expect($bare)
            ->toContain('count(*)')
            ->toContain('inner join roles')
            ->toContain('environment_id')
            ->toContain('permission_id in');
    }
});

it('never counts another environment\'s roles toward this one\'s permission usage', function (): void {
    platformRootEnvironment();
    $acme = usageEnvironment('Acme', 'owner@acme.example');
    $globex = usageEnvironment('Globex', 'owner@globex.example');

    $permission = Permission::query()->create([
        'client_id' => null, 'environment_id' => $acme['env'], 'name' => 'reports:read', 'tenant_assignable' => true,
    ]);

    // One binding from Acme's own role — the only one this page may count.
    bindPermission(roleIn($acme['env'], 'acme-reviewer')->id, $permission->id);

    // Three from ANOTHER environment's roles. Nothing in the pivot's schema prevents
    // this — it has no `environment_id` — so the count has to exclude them itself.
    foreach (range(1, 3) as $i) {
        bindPermission(roleIn($globex['env'], 'globex-'.$i)->id, $permission->id);
    }

    serveOnTestHost($acme['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($acme['env']));
    actAsEnvironmentAdmin($acme['subjectId'], $acme['env']);

    // ONE, not four. The un-joined count reported all four, telling Acme's admin that
    // deleting this permission would strip it from roles they cannot see.
    test()->get(route('environment.permissions'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'mine',
            fn (Collection $rows): bool => $rows
                ->firstWhere('name', 'reports:read')['roleCount'] === 1,
        ));
});
