<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** Provision an env + pin an env-admin session. Returns the environment id. */
function permSetup(string $accountName = 'Acme', string $ownerEmail = 'owner@acme.example'): string
{
    // The permissions page lives under `/admin`, which exists only on a multi-tenant
    // deployment. {@see \App\Http\Middleware\RequireMultiTenant}.
    multiTenantDeployment();

    platformRootEnvironment();
    $r = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: $accountName,
        ownerEmail: $ownerEmail,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($r->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
    actAsEnvironmentAdmin($r->owner->id, $r->environment->id);

    return $r->environment->id;
}

it('renders the permissions page with both sources distinguished', function (): void {
    $env = permSetup();
    Permission::query()->create(['client_id' => null, 'environment_id' => $env, 'name' => 'reports:read', 'tenant_assignable' => true]);
    Permission::query()->create(['client_id' => 'client_app_1', 'environment_id' => $env, 'name' => 'app:action', 'tenant_assignable' => true]);

    $this->get('/admin/permissions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/permissions')
            ->where('title', 'Permissions')
            // Told apart by which LIST they arrive in, not by a badge in the markup: the
            // page's whole job is that a key an app owns and a key somebody wrote here are
            // never mistaken for each other.
            ->where('mine', fn (Collection $rows): bool => $rows->pluck('name')->all() === ['reports:read'])
            ->where('declared.0.permissions', fn (Collection $rows): bool => $rows->pluck('name')->all() === ['app:action']));
});

it('creates a manual permission (client_id null, source = manual)', function (): void {
    permSetup();

    createPermission(['name' => 'invoices:create', 'description' => 'Create invoices'], 'environment.permissions')
        ->assertSessionHasNoErrors();

    $perm = Permission::query()->where('name', 'invoices:create')->first();
    expect($perm)->not->toBeNull()
        ->and($perm->client_id)->toBeNull()
        ->and($perm->tenant_assignable)->toBeTrue();
    // The authoring environment is stamped, not the platform-global (null) namespace.
    expect($perm->environment_id)->not->toBeNull();
});

it('rejects a bad key format and a duplicate manual key', function (): void {
    $env = permSetup();

    createPermission(['name' => 'not a key'], 'environment.permissions')->assertSessionHasErrors('name');

    Permission::query()->create(['client_id' => null, 'environment_id' => $env, 'name' => 'reports:read', 'tenant_assignable' => true]);

    createPermission(['name' => 'reports:read'], 'environment.permissions')->assertSessionHasErrors('name');

    // And the same key in different case, which the DB unique never sees on a manual row:
    // it is lower-cased on the way in, so this is a duplicate rather than a second key.
    createPermission(['name' => 'Reports:Read'], 'environment.permissions')->assertSessionHasErrors('name');

    expect(Permission::query()->where('name', 'like', '%eports:%ead')->count())->toBe(1);
});

it('edits and deletes a manual permission, cascading its role links', function (): void {
    $env = permSetup();
    $perm = Permission::query()->create(['client_id' => null, 'environment_id' => $env, 'name' => 'billing:manage', 'tenant_assignable' => true]);

    // A REAL role holding it, so the delete goes through the path that records what it
    // took away...
    $role = app(Roles::class)->define(null, 'Billing');
    app(Roles::class)->attachPermission($role->id, $perm->id);

    // ...and a grant whose role no longer resolves, which the pivot allows because it
    // carries no foreign key. The contract cannot revoke a grant on a role it refuses to
    // load, so this is the row that used to be left pointing at a deleted permission.
    DB::table('role_permission')->insert(['role_id' => 'role_x', 'permission_id' => $perm->id]);

    test()->from(route('environment.permissions'))
        ->patch(route('environment.permissions.update', $perm->id), [
            'description' => 'Manage billing',
            'tenantAssignable' => false,
        ])
        ->assertSessionHasNoErrors();

    $perm->refresh();
    expect($perm->description)->toBe('Manage billing')->and($perm->tenant_assignable)->toBeFalse();

    test()->delete(route('environment.permissions.destroy', $perm->id));

    expect(Permission::query()->whereKey($perm->id)->exists())->toBeFalse()
        ->and(DB::table('role_permission')->where('permission_id', $perm->id)->exists())->toBeFalse()
        // THE RECORD THE RAW DELETE NEVER LEFT. Removing a key from every role that
        // grants it is a change to privileged access, and it used to leave nothing on
        // /audit and nothing for a SIEM.
        ->and(AuditEntry::query()->where('action', 'role.permission_revoked')->count())->toBe(1);
});

it('refuses to edit or delete an APP-declared permission (the app owns it)', function (): void {
    permSetup();
    $app = Permission::query()->create(['client_id' => 'client_app_1', 'name' => 'app:action', 'tenant_assignable' => true]);

    // 404 rather than 403: the write set is resolved as a QUERY — manual, in this
    // environment, owned by this author — so an app-declared key is never a row a
    // mutation here can reach, and there is nothing to refuse afterwards.
    test()->patch(route('environment.permissions.update', $app->id), ['description' => 'Mine now'])
        ->assertNotFound();
    test()->delete(route('environment.permissions.destroy', $app->id))->assertNotFound();

    expect(Permission::query()->whereKey($app->id)->exists())->toBeTrue()
        ->and(Permission::query()->whereKey($app->id)->value('description'))->toBeNull();
});

// The confirmed P1: one environment's admin could see, edit, and DELETE another
// environment's (or an operator's platform-global) manual permission — cascading the
// role_permission purge across tenants — because manual permissions lived in the global
// null-environment namespace. Manual permissions are now stamped with their authoring
// environment and the resolver is environment-scoped, so neither is reachable.
it('isolates manual permissions to their authoring environment', function (): void {
    // Environment A authors a manual permission and binds it into a role.
    $envA = permSetup('Acme', 'owner@acme.example');
    $permA = Permission::query()->create(['client_id' => null, 'environment_id' => $envA, 'name' => 'secrets:rotate', 'tenant_assignable' => true]);
    DB::table('role_permission')->insert(['role_id' => 'role_a', 'permission_id' => $permA->id]);

    // A legacy platform-global (null-environment) manual permission, as pre-fix rows exist.
    $legacy = Permission::query()->create(['client_id' => null, 'environment_id' => null, 'name' => 'legacy:global', 'tenant_assignable' => true]);

    /*
     * Switch to a DIFFERENT tenant's env-admin session — and hand them the host with it.
     *
     * An environment is reached at its own domain, and the suite has one. Left on A, every
     * request below would land on A's host carrying B's session and be redirected to open
     * B's environment — a 302 that proves nothing about isolation. Releasing it first is
     * what makes these requests B's administrator on B's console, which is the shape the
     * isolation claim is about.
     */
    Environment::query()->whereKey($envA)->update(['domain' => null, 'domain_verified_at' => null]);

    permSetup('Beta', 'owner@beta.example');

    // B's console lists neither A's env-scoped permission nor the operator-global one.
    test()->get(route('environment.permissions'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('mine', fn (Collection $rows): bool => $rows
                ->pluck('name')
                ->intersect(['secrets:rotate', 'legacy:global'])
                ->isEmpty()));

    // And B can neither edit nor delete either — the resolver is environment-scoped.
    test()->patch(route('environment.permissions.update', $permA->id), ['description' => 'Mine now'])
        ->assertNotFound();
    test()->delete(route('environment.permissions.destroy', $permA->id))->assertNotFound();
    test()->delete(route('environment.permissions.destroy', $legacy->id))->assertNotFound();

    // Both permissions — and A's role link — survive B's attempt untouched.
    expect(Permission::query()->withoutGlobalScopes()->whereKey($permA->id)->exists())->toBeTrue()
        ->and(Permission::query()->withoutGlobalScopes()->whereKey($legacy->id)->exists())->toBeTrue()
        ->and(DB::table('role_permission')->where('permission_id', $permA->id)->exists())->toBeTrue();
});

/**
 * The catalog is not bounded by anyone who reads this page.
 *
 * App-declared permissions arrive from a manifest push: an integration decides how many
 * there are, and this page rendered every one of them on every render — and again in the
 * Livewire payload on every action taken anywhere on it. A tenant with a large app
 * catalog got a page that was slow to open and impossible to look anything up in.
 *
 * The bound is worth nothing if it hides rows with no way back to them, so the two halves
 * are asserted together: the list stops, it says so, and search reaches past it.
 */
it('bounds the app-declared catalog and lets search reach past it', function (): void {
    $env = permSetup();

    // One page's worth plus a tail, named so the last one cannot be confused for the
    // first alphabetically — the list is ordered by name.
    for ($i = 0; $i < 60; $i++) {
        Permission::query()->create([
            'client_id' => 'client_app_1',
            'environment_id' => $env,
            'name' => sprintf('app:action%02d', $i),
            'tenant_assignable' => true,
        ]);
    }

    Permission::query()->create([
        'client_id' => 'client_app_1',
        'environment_id' => $env,
        'name' => 'zebra:groom',
        'tenant_assignable' => true,
    ]);

    test()->get(route('environment.permissions'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('declared.0.permissions', fn (Collection $rows): bool => $rows
                ->pluck('name')
                ->doesntContain('zebra:groom'))
            // And the page is TOLD the list stopped, rather than it simply ending — the
            // bound is worth nothing if it hides rows with no way back to them.
            ->where('declaredShown', 50)
            ->where('declaredTotal', 61));

    test()->get(route('environment.permissions', ['q' => 'zebra']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('declared.0.permissions', fn (Collection $rows): bool => $rows
                ->pluck('name')
                ->contains('zebra:groom')));
});

/**
 * "Who holds this role?" had no answer anywhere in the console.
 *
 * An access review enumerates one organization's grants, and an environment-wide grant
 * belongs to none — so a grant that applies in every organization was visible only by
 * opening every user page in turn. A role whose holders you cannot see is a role you
 * cannot govern, and this is the cheap half of that gap.
 */
it('lists who holds a role, and how they hold it', function (): void {
    $env = permSetup();

    $roles = app(Roles::class);
    $support = $roles->define(null, 'Support');

    $org = app(Organizations::class)->create(new NewOrganization('Acme Tenant', 'acme-tenant'));

    $everywhere = app(Subjects::class)->create('agent@acme.test', 'Agent', 'a-strong-unbreached-passphrase')->id;
    $inOrg = app(Subjects::class)->create('member@acme.test', 'Member', 'a-strong-unbreached-passphrase')->id;

    $roles->assignEverywhere($everywhere, $support->id);
    $roles->assign($org->id, $inOrg, $support->id);

    test()->get(route('environment.roles.show', $support->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/roles/show')
            // The two grants are not the same statement, and the scope is what says which
            // is which: null means every organization in the environment, including for
            // somebody who belongs to none.
            ->where('holders', fn (Collection $holders): bool => $holders
                ->map(fn (array $holder): array => [$holder['email'], $holder['scope']])
                ->sortBy(0)
                ->values()
                ->all() === [
                    ['agent@acme.test', null],
                    ['member@acme.test', 'Acme Tenant'],
                ]));
});
