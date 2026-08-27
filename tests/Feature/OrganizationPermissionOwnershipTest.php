<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * The permission catalog, fenced by ORGANIZATION.
 *
 * The console offers the authoring form on both planes — an organization administrator
 * can reach it, which is deliberate: roles are made of permissions, and a plane that
 * offers one while hiding the other asks an admin to assign a thing they cannot inspect.
 * What was NOT deliberate is where the rows landed. Every manual permission carried an
 * environment and no owner, so a tenant admin's "Add permission" wrote into a catalog
 * shared with every peer in the environment, their Edit renamed a key those peers' roles
 * were built from, and their Delete cascaded `role_permission` for every role in it.
 *
 * The sibling file EnvironmentPermissionsTest holds the environment boundary. This one
 * holds the tenant boundary INSIDE a single environment, which is the harder of the two:
 * both organizations here resolve the same `environment_id`, so every assertion below
 * fails the moment the owner clause is the only thing removed.
 */

/** Sign in an owner of a fresh organization on the console plane. Returns the org id. */
function permOrgAdmin(string $slug, string $email): string
{
    $subject = app(Subjects::class)->create($email, 'Owner', 'a-strong-unbreached-passphrase');
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $org = app(Organizations::class)->create(new NewOrganization(ucfirst($slug), $slug));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $org->id;
}

/** The environment both organizations below live in. */
function permEnvironmentId(): string
{
    $environment = app(EnvironmentContext::class)->current();

    expect($environment)->not->toBeNull();

    return $environment->environmentKey();
}

it('stamps a tenant-authored permission with its authoring organization', function (): void {
    $orgA = permOrgAdmin('acme', 'owner@acme.example');

    createPermission(['name' => 'invoices:create', 'description' => 'Create invoices'])
        ->assertSessionHasNoErrors();

    $perm = Permission::query()->withoutGlobalScopes()->where('name', 'invoices:create')->firstOrFail();

    // The owner, not merely the environment. Without it the row lands in the shared tier
    // and every assertion in the isolation test below becomes unenforceable.
    expect($perm->organization_id)->toBe($orgA)
        ->and($perm->environment_id)->not->toBeNull()
        ->and($perm->client_id)->toBeNull()
        // The org plane does not offer the choice, so it must not write a row a tenant
        // cannot then compose into their own roles.
        ->and($perm->tenant_assignable)->toBeTrue();
});

it('hides one organization\'s permissions from another in the same environment', function (): void {
    permOrgAdmin('acme', 'owner@acme.example');
    $environment = permEnvironmentId();

    createPermission(['name' => 'secrets:rotate'])->assertSessionHasNoErrors();
    $theirs = Permission::query()->withoutGlobalScopes()->where('name', 'secrets:rotate')->firstOrFail();

    // The shared tier, as the environment plane writes it: same environment, no owner.
    $shared = Permission::query()->create([
        'client_id' => null,
        'environment_id' => $environment,
        'organization_id' => null,
        'name' => 'reports:read',
        'tenant_assignable' => true,
    ]);

    // A PEER in the SAME environment. Both organizations resolve `$environment`, so the
    // environment clause that already existed cannot be what makes this pass.
    permOrgAdmin('beta', 'owner@beta.example');

    expect($theirs->environment_id)->toBe($environment);

    test()->get(route('permissions'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // The peer's own key is in neither list — not theirs, and not shared.
            ->where('mine', fn (Collection $rows): bool => $rows->pluck('name')->doesntContain('secrets:rotate'))
            ->where('inherited', fn (Collection $rows): bool => $rows->pluck('name')->doesntContain('secrets:rotate'))
            // The environment's, which their roles ARE built from, is inherited: shown,
            // and read-only. A page that omitted it would explain half the roles editor.
            ->where('inherited', fn (Collection $rows): bool => $rows->pluck('name')->contains('reports:read')));
});

it('refuses to let one organization edit or delete another\'s permission', function (): void {
    permOrgAdmin('acme', 'owner@acme.example');
    $environment = permEnvironmentId();

    createPermission(['name' => 'secrets:rotate'])->assertSessionHasNoErrors();
    $theirs = Permission::query()->withoutGlobalScopes()->where('name', 'secrets:rotate')->firstOrFail();
    DB::table('role_permission')->insert(['role_id' => 'role_a', 'permission_id' => $theirs->id]);

    $shared = Permission::query()->create([
        'client_id' => null,
        'environment_id' => $environment,
        'organization_id' => null,
        'name' => 'reports:read',
        'tenant_assignable' => true,
    ]);
    DB::table('role_permission')->insert(['role_id' => 'role_shared', 'permission_id' => $shared->id]);

    permOrgAdmin('beta', 'owner@beta.example');

    // The peer's private key: not resolvable for writing, so there is nothing to refuse
    // afterwards — the write set is a query, and a forged id matches nothing in it.
    test()->patch(route('permissions.update', $theirs->id), ['description' => 'Mine now'])->assertNotFound();
    test()->delete(route('permissions.destroy', $theirs->id))->assertNotFound();

    // The SHARED key: visible, composable into roles, and still not writable from a
    // tenant plane. This is the pair `visibleToOrganization` and `ownedByOrganization`
    // exist to keep apart — a fence built from the visibility predicate alone would let
    // this delete through and take every role in the environment's grant with it.
    test()->patch(route('permissions.update', $shared->id), ['description' => 'Mine now'])->assertNotFound();
    test()->delete(route('permissions.destroy', $shared->id))->assertNotFound();

    expect(Permission::query()->withoutGlobalScopes()->whereKey($theirs->id)->exists())->toBeTrue()
        ->and(Permission::query()->withoutGlobalScopes()->whereKey($shared->id)->exists())->toBeTrue()
        ->and(DB::table('role_permission')->where('permission_id', $theirs->id)->exists())->toBeTrue()
        ->and(DB::table('role_permission')->where('permission_id', $shared->id)->exists())->toBeTrue();
});

it('lets a tenant author a key a peer already owns, without disclosing that they own it', function (): void {
    permOrgAdmin('acme', 'owner@acme.example');
    createPermission(['name' => 'billing:refund'])->assertSessionHasNoErrors();

    permOrgAdmin('beta', 'owner@beta.example');

    // No collision error: the uniqueness probe runs over what THIS tenant can see, so it
    // never becomes an oracle for a peer's `feature:action` names — which are named after
    // what that peer bought.
    createPermission(['name' => 'billing:refund'])->assertSessionHasNoErrors();

    expect(Permission::query()->withoutGlobalScopes()->where('name', 'billing:refund')->count())->toBe(2);
});

it('still refuses a key the environment already shares', function (): void {
    permOrgAdmin('acme', 'owner@acme.example');

    Permission::query()->create([
        'client_id' => null,
        'environment_id' => permEnvironmentId(),
        'organization_id' => null,
        'name' => 'reports:read',
        'tenant_assignable' => true,
    ]);

    // Two identical keys in the Roles editor, one shared and one private, is a choice
    // nobody can make correctly — so the visible half of the probe still fires.
    createPermission(['name' => 'reports:read'])->assertSessionHasErrors('name');
});
