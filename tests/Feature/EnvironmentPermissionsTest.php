<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** Provision an env + pin an env-admin session. */
function permSetup(): void
{
    $r = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    config(['cbox-id.environments.default' => $r->environment->id]);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
    session()->put(EnvironmentAdminAuth::SESSION_KEY, $r->member->id);
    session()->put(EnvironmentAdminAuth::ENV_KEY, $r->environment->id);
}

it('renders the permissions page with both sources distinguished', function (): void {
    permSetup();
    Permission::query()->create(['client_id' => null, 'name' => 'reports:read', 'tenant_assignable' => true]);
    Permission::query()->create(['client_id' => 'client_app_1', 'name' => 'app:action', 'tenant_assignable' => true]);

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

    Volt::test('environment.permissions.index')
        ->set('name', 'invoices:create')
        ->set('description', 'Create invoices')
        ->set('tenantAssignable', true)
        ->call('create')
        ->assertHasNoErrors();

    $perm = Permission::query()->where('name', 'invoices:create')->first();
    expect($perm)->not->toBeNull()
        ->and($perm->client_id)->toBeNull()
        ->and($perm->tenant_assignable)->toBeTrue();
});

it('rejects a bad key format and a duplicate manual key', function (): void {
    permSetup();

    Volt::test('environment.permissions.index')
        ->set('name', 'not a key')
        ->call('create')
        ->assertHasErrors('name');

    Permission::query()->create(['client_id' => null, 'name' => 'reports:read', 'tenant_assignable' => true]);

    Volt::test('environment.permissions.index')
        ->set('name', 'reports:read')
        ->call('create')
        ->assertHasErrors('name');
});

it('edits and deletes a manual permission, cascading its role links', function (): void {
    permSetup();
    $perm = Permission::query()->create(['client_id' => null, 'name' => 'billing:manage', 'tenant_assignable' => true]);
    DB::table('role_permission')->insert(['role_id' => 'role_x', 'permission_id' => $perm->id]);

    Volt::test('environment.permissions.index')
        ->call('startEdit', $perm->id)
        ->set('editDescription', 'Manage billing')
        ->set('editTenantAssignable', false)
        ->call('saveEdit')
        ->assertHasNoErrors();

    $perm->refresh();
    expect($perm->description)->toBe('Manage billing')->and($perm->tenant_assignable)->toBeFalse();

    Volt::test('environment.permissions.index')->call('delete', $perm->id);

    expect(Permission::query()->whereKey($perm->id)->exists())->toBeFalse()
        ->and(DB::table('role_permission')->where('permission_id', $perm->id)->exists())->toBeFalse();
});

it('refuses to edit or delete an APP-declared permission (the app owns it)', function (): void {
    permSetup();
    $app = Permission::query()->create(['client_id' => 'client_app_1', 'name' => 'app:action', 'tenant_assignable' => true]);

    Volt::test('environment.permissions.index')
        ->call('startEdit', $app->id)
        ->assertSet('editingId', null);

    Volt::test('environment.permissions.index')->call('delete', $app->id);

    expect(Permission::query()->whereKey($app->id)->exists())->toBeTrue();
});
