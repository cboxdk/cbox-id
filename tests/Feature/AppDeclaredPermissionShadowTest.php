<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Manifest\ManifestParser;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A key an app declares belongs to that app. An organization's manual permission shares the
 * unqualified `feature:action` namespace, and tokens carry permission NAMES — so a manual
 * row spelled like an app's key would read, to that app, as its own key. Where the app kept
 * the key off the tenant plane (`tenant_assignable: false`) that is the app's staff-only
 * access in a tenant's hands.
 *
 * Two halves: the console refuses to author such a name, and a token for an app carries a
 * key that app declares only when the user holds it through that app's own declared row —
 * which also covers the order the console cannot see (the manual key came first, the app
 * declared the same key later).
 */
function shadowApp(): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: 'Ledger',
        redirectUris: ['https://ledger.test/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        firstParty: true,
    ))->client->client_id;
}

/** @param list<array{key: string, tenant_assignable?: bool}> $permissions */
function shadowDeclare(string $clientId, array $permissions, array $roles = []): void
{
    app(AppManifests::class)->sync($clientId, app(ManifestParser::class)->parse([
        'version' => 'test',
        'permissions' => $permissions,
        'roles' => $roles,
    ]));
}

it('refuses a manual permission named like a key an app declares', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $ledger = shadowApp();
    shadowDeclare($ledger, [['key' => 'billing:refund', 'tenant_assignable' => false]]);

    expect(Permission::query()->withoutGlobalScopes()->where('client_id', $ledger)->where('name', 'billing:refund')->exists())->toBeTrue();

    // Case does not make it a different key.
    foreach (['billing:refund', 'Billing:Refund'] as $name) {
        createPermission(['name' => $name])->assertSessionHasErrors('name');
    }

    expect(Permission::query()->withoutGlobalScopes()->whereNull('client_id')->where('name', 'billing:refund')->exists())->toBeFalse();

    // A key no app declares is still the administrator's to write.
    createPermission(['name' => 'reports:read'])->assertSessionHasNoErrors();
    expect(Permission::query()->withoutGlobalScopes()->whereNull('client_id')->where('organization_id', $org->id)->where('name', 'reports:read')->exists())->toBeTrue();
})->group('security');

it('keeps a manual key out of an app\'s token when that app declares the same key later', function (): void {
    [$owner, $org] = actingAsRole(MembershipRole::Owner);
    $ledger = shadowApp();

    // Written while no app declared it, so the console had nothing to refuse.
    createPermission(['name' => 'billing:refund'])->assertSessionHasNoErrors();
    createPermission(['name' => 'reports:read'])->assertSessionHasNoErrors();

    $roles = app(Roles::class);
    $role = $roles->define($org->id, 'Refunds');
    foreach (['billing:refund', 'reports:read'] as $name) {
        $manual = Permission::query()->withoutGlobalScopes()->whereNull('client_id')->where('organization_id', $org->id)->where('name', $name)->sole();
        $roles->attachPermission($role->id, $manual->id, $org->id);
    }

    $member = app(Subjects::class)->create('dana@acme.test', 'Dana');
    app(Memberships::class)->add($org->id, $member->id, MembershipRole::Member);
    $roles->assign($org->id, $member->id, $role->id);

    // The app now declares the key, and keeps it staff-only.
    shadowDeclare($ledger, [['key' => 'billing:refund', 'tenant_assignable' => false]]);

    $claims = app(AccessChecker::class)->forToken($member->id, $org->id, $ledger);

    expect($claims->permissions)->not->toContain('billing:refund')
        // The organization's own vocabulary that no app claims is untouched.
        ->and($claims->permissions)->toContain('reports:read');
})->group('security');

it('still carries a declared key granted through the app\'s own declared row', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $ledger = shadowApp();
    shadowDeclare(
        $ledger,
        [['key' => 'billing:refund', 'tenant_assignable' => false]],
        [['key' => 'support', 'name' => 'Support', 'permissions' => ['billing:refund'], 'tenant_assignable' => false]],
    );

    $support = Role::query()->where('client_id', $ledger)->where('key', 'support')->sole();
    $declared = Permission::query()->withoutGlobalScopes()->where('client_id', $ledger)->where('name', 'billing:refund')->sole();
    app(Roles::class)->attachPermission($support->id, $declared->id);

    $agent = app(Subjects::class)->create('sam@vendor.test', 'Sam');
    app(Memberships::class)->add($org->id, $agent->id, MembershipRole::Member);
    app(Roles::class)->assign($org->id, $agent->id, $support->id);

    expect(app(AccessChecker::class)->forToken($agent->id, $org->id, $ledger)->permissions)->toContain('billing:refund');
})->group('security');
