<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

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
        ->assertSee('Permissions')
        ->assertSee('Manual')        // the manual-source badge + section
        ->assertSee('App-declared')  // the synced section
        ->assertSee('reports:read')
        ->assertSee('app:action');
});

it('creates a manual permission (client_id null, source = manual)', function (): void {
    permSetup();

    Volt::test('console.permissions.index')
        ->set('name', 'invoices:create')
        ->set('description', 'Create invoices')
        ->set('tenantAssignable', true)
        ->call('create')
        ->assertHasNoErrors();

    $perm = Permission::query()->where('name', 'invoices:create')->first();
    expect($perm)->not->toBeNull()
        ->and($perm->client_id)->toBeNull()
        ->and($perm->tenant_assignable)->toBeTrue();
    // The authoring environment is stamped, not the platform-global (null) namespace.
    expect($perm->environment_id)->not->toBeNull();
});

it('rejects a bad key format and a duplicate manual key', function (): void {
    $env = permSetup();

    Volt::test('console.permissions.index')
        ->set('name', 'not a key')
        ->call('create')
        ->assertHasErrors('name');

    Permission::query()->create(['client_id' => null, 'environment_id' => $env, 'name' => 'reports:read', 'tenant_assignable' => true]);

    Volt::test('console.permissions.index')
        ->set('name', 'reports:read')
        ->call('create')
        ->assertHasErrors('name');
});

it('edits and deletes a manual permission, cascading its role links', function (): void {
    $env = permSetup();
    $perm = Permission::query()->create(['client_id' => null, 'environment_id' => $env, 'name' => 'billing:manage', 'tenant_assignable' => true]);
    DB::table('role_permission')->insert(['role_id' => 'role_x', 'permission_id' => $perm->id]);

    Volt::test('console.permissions.index')
        ->call('startEdit', $perm->id)
        ->set('editDescription', 'Manage billing')
        ->set('editTenantAssignable', false)
        ->call('saveEdit')
        ->assertHasNoErrors();

    $perm->refresh();
    expect($perm->description)->toBe('Manage billing')->and($perm->tenant_assignable)->toBeFalse();

    Volt::test('console.permissions.index')->call('delete', $perm->id);

    expect(Permission::query()->whereKey($perm->id)->exists())->toBeFalse()
        ->and(DB::table('role_permission')->where('permission_id', $perm->id)->exists())->toBeFalse();
});

it('refuses to edit or delete an APP-declared permission (the app owns it)', function (): void {
    permSetup();
    $app = Permission::query()->create(['client_id' => 'client_app_1', 'name' => 'app:action', 'tenant_assignable' => true]);

    Volt::test('console.permissions.index')
        ->call('startEdit', $app->id)
        ->assertSet('editingId', null);

    Volt::test('console.permissions.index')->call('delete', $app->id);

    expect(Permission::query()->whereKey($app->id)->exists())->toBeTrue();
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

    // Switch to a DIFFERENT tenant's env-admin session.
    permSetup('Beta', 'owner@beta.example');

    // B's console lists neither A's env-scoped permission nor the operator-global one.
    Volt::test('console.permissions.index')
        ->assertDontSee('secrets:rotate')
        ->assertDontSee('legacy:global');

    // And B can neither edit nor delete either — the resolver is environment-scoped.
    Volt::test('console.permissions.index')->call('startEdit', $permA->id)->assertSet('editingId', null);
    Volt::test('console.permissions.index')->call('delete', $permA->id);
    Volt::test('console.permissions.index')->call('delete', $legacy->id);

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

    $page = Volt::test('console.permissions.index');

    $page->assertDontSee('zebra:groom')
        // And it says the list stopped, rather than simply ending.
        ->assertSee('Showing 50 of 61');

    $page->set('search', 'zebra')->assertSee('zebra:groom');
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

    $html = Volt::test('console.roles.show', ['role' => $support->id])->html();

    expect($html)
        ->toContain('Who holds this')
        ->toContain('agent@acme.test')
        ->toContain('member@acme.test')
        // The two grants are not the same statement, and the page says which is which.
        ->toContain('Everywhere')
        ->toContain('Acme Tenant');
});
