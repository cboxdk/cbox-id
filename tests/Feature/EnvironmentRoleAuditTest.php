<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

    /*
     * Provision an account + environment, pin the environment context and act as one of
     * its admins — the plane the role console lives on.
     *
     * MULTI-TENANT, said out loud. The `/admin` prefix is gated on a member administering
     * one of their organization's environments, and a single-tenant install has one
     * environment that belongs to nobody — so the whole prefix 404s on that shape. It did
     * not matter while these were driven at the component; every mutation is a request
     * now, and without this each one answers 404 rather than doing anything.
     */
    multiTenantDeployment();
    platformRootEnvironment();
    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->owner->id, $provisioned->environment->id);
});

/**
 * The environment role console wrote straight to the tables with `DB::table()`:
 * deleting a role dropped its permission pivot AND every live assignment of it with no
 * observer, no FK cascade and no event. A change to privileged access affecting every
 * holder therefore left ZERO trace — nothing on /audit, nothing for a SIEM, and no
 * `role.unassigned` for the downstream apps that mirror grants off it.
 *
 * (The privilege itself was never stale: roles are resolved live at token-mint time, so
 * a refreshed token stops carrying a deleted role the moment its rows are gone. What
 * was missing was the record.)
 */
it('audits and announces a role deletion, and unassigns every holder on the record', function (): void {
    $role = app(Roles::class)->define(null, 'Support');
    $permission = Permission::query()->create(['name' => 'reports:view']);

    app(Roles::class)->attachPermission($role->id, $permission->id);
    app(Roles::class)->assign('org_a', 'user_1', $role->id);
    app(Roles::class)->assign('org_a', 'user_2', $role->id);

    test()->delete(route('environment.roles.destroy', $role->id))
        ->assertRedirect(route('environment.roles'));

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()
        ->and(RoleAssignment::query()->where('role_id', $role->id)->count())->toBe(0)
        ->and(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(0);

    // The compliance record the raw deletes never left.
    expect(AuditEntry::query()->where('action', 'role.deleted')->count())->toBe(1)
        ->and(AuditEntry::query()->where('action', 'role.unassigned')->count())->toBe(2);

    // ...and the webhook stream downstream apps reconcile against.
    $deleted = Event::query()->where('type', 'role.deleted')->sole();

    expect($deleted->payload['user_ids'])->toEqualCanonicalizing(['user_1', 'user_2'])
        ->and(Event::query()->where('type', 'role.unassigned')->count())->toBe(2);
});

it('audits a permission being granted and revoked on a role', function (): void {
    $role = app(Roles::class)->define(null, 'Support');
    $permission = Permission::query()->create(['name' => 'reports:view']);

    setRolePermission($role->id, $permission->id, true, 'environment.roles')
        ->assertSessionHasNoErrors();

    expect(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(1)
        ->and(AuditEntry::query()->where('action', 'role.permission_granted')->count())->toBe(1);

    setRolePermission($role->id, $permission->id, false, 'environment.roles')
        ->assertSessionHasNoErrors();

    expect(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'role.permission_revoked')->count())->toBe(1);
});

it('audits a rename with the values on both sides of it', function (): void {
    $role = app(Roles::class)->define(null, 'Support');

    test()->from(route('environment.roles.show', $role->id))
        ->patch(route('environment.roles.update', $role->id), [
            'name' => 'Support Renamed',
            'description' => 'Support staff',
        ])
        ->assertSessionHasNoErrors();

    $entry = AuditEntry::query()->where('action', 'role.updated')->sole();

    expect(Role::query()->whereKey($role->id)->value('name'))->toBe('Support Renamed')
        ->and($entry->context['from']['name'])->toBe('Support')
        ->and($entry->context['to']['name'])->toBe('Support Renamed');
});

it('still refuses every mutation on an app-declared role', function (): void {
    $role = app(Roles::class)->define(null, 'Managed');
    $role->source = RoleSource::Manifest;
    $role->save();

    $permission = Permission::query()->create(['name' => 'reports:view']);

    test()->delete(route('environment.roles.destroy', $role->id))->assertForbidden();
    test()->patch(route('environment.roles.update', $role->id), ['name' => 'Taken', 'description' => ''])
        ->assertForbidden();
    // A REAL permission id, not 'x'. Refused because the ROLE is the app's, which is a
    // different refusal from "that permission does not exist" — and the unresolvable id
    // would have passed this test with the role gate deleted.
    setRolePermission($role->id, $permission->id, true, 'environment.roles')->assertForbidden();

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue()
        ->and(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'role.deleted')->count())->toBe(0);
});
