<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Platform\AuditNames;
use App\Platform\GrantAccessRole;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Exceptions\RoleNotTenantAssignable;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\InvitationStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Invitation;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewCustomerApiKey;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| The environment management API's tenancy endpoints
|--------------------------------------------------------------------------
|
| What an app vendor's backend needs to run its customers' teams: an organization with an
| owner, members, invitations with roles and a way back to the app, role grants (in one
| organization, and everywhere for staff), apps, APIs, customer API keys and support
| sessions. Every request resolves to `env_test` (TestCase's default host fallback).
*/

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Mail::fake();
});

/**
 * A key in env_test holding exactly these scopes, and its row.
 *
 * @param  list<EnvironmentApiScope>  $scopes
 * @return array{0: string, 1: EnvironmentApiKey}
 */
function tenancyKey(array $scopes = []): array
{
    $issued = app(EnvironmentApiKeys::class)->issue(
        'env_test',
        'Tenancy worker',
        array_map(fn (EnvironmentApiScope $s): string => $s->value, $scopes === [] ? EnvironmentApiScope::offerable() : $scopes),
    );

    return [$issued->plaintext, $issued->key];
}

function tenancyUser(string $email, ?string $name = null): string
{
    return app(Subjects::class)->create($email, $name ?? ucfirst(strstr($email, '@', true) ?: $email))->id;
}

function tenancyOrg(string $name, ?string $ownerId = null): Organization
{
    $organization = app(Organizations::class)->create(new NewOrganization($name, str($name)->slug()->toString()));

    if ($ownerId !== null) {
        app(Memberships::class)->add($organization->id, $ownerId, MembershipRole::Owner);
    }

    return $organization;
}

/** Run in ANOTHER environment than the one every request here resolves to. */
function inOtherEnvironment(Closure $callback): mixed
{
    return app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), $callback);
}

/** An app that declares roles in its manifest, the way a vendor's app does. */
function tenancyApp(string $name = 'Tax', array $changes = []): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: $name,
        type: ClientType::Confidential,
        redirectUris: ['https://tax.example/callback'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'profile', 'email'],
        firstParty: $changes['firstParty'] ?? true,
        apiKeyPrefix: $changes['apiKeyPrefix'] ?? null,
    ))->client;
}

/** A role declared by an app's manifest, optionally staff-only. */
function tenancyAppRole(Client $app, string $key, bool $tenantAssignable = true, array $permissions = []): Role
{
    $role = app(Roles::class)->define(null, ucfirst($key), null, $app->client_id, $tenantAssignable);
    $role->forceFill(['key' => $key])->save();

    foreach ($permissions as $permission) {
        app(Roles::class)->grantPermission(null, $role->id, $permission);
    }

    return $role;
}

/** The newest entry for an action — on one organization's trail, when named (sequences are per trail). */
function auditFor(string $action, ?string $organizationId = null): ?AuditEntry
{
    return AuditEntry::query()
        ->where('action', $action)
        ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
        ->orderByDesc('id')
        ->first();
}

/*
| Scopes
*/

it('refuses every tenancy endpoint to a key without its scope, naming the scope', function (string $method, string $uri, string $scope): void {
    // Every OTHER offered scope: the key is as powerful as it gets without the one needed.
    $others = array_values(array_filter(EnvironmentApiScope::offerable(), fn (EnvironmentApiScope $s): bool => $s->value !== $scope));
    [$key] = tenancyKey($others);

    $this->withToken($key)->json($method, $uri, [])
        ->assertForbidden()
        ->assertJsonPath('error', 'forbidden')
        ->assertJsonPath('message', "This key is missing the required scope: {$scope}.");
})->with([
    ['PATCH', '/api/v1/organizations/o1', 'organizations:write'],
    ['DELETE', '/api/v1/organizations/o1', 'organizations:write'],
    ['POST', '/api/v1/organizations/o1/transfer-ownership', 'organizations:write'],
    ['GET', '/api/v1/organizations/o1/members', 'members:read'],
    ['POST', '/api/v1/organizations/o1/members', 'members:write'],
    ['PATCH', '/api/v1/organizations/o1/members/u1', 'members:write'],
    ['DELETE', '/api/v1/organizations/o1/members/u1', 'members:write'],
    ['GET', '/api/v1/organizations/o1/members/u1/roles', 'roles:read'],
    ['PUT', '/api/v1/organizations/o1/members/u1/roles/r1', 'roles:write'],
    ['DELETE', '/api/v1/organizations/o1/members/u1/roles/r1', 'roles:write'],
    ['GET', '/api/v1/organizations/o1/invitations', 'invitations:read'],
    ['POST', '/api/v1/organizations/o1/invitations', 'invitations:write'],
    ['DELETE', '/api/v1/organizations/o1/invitations/i1', 'invitations:write'],
    ['POST', '/api/v1/organizations/o1/invitations/i1/resend', 'invitations:write'],
    ['GET', '/api/v1/organizations/o1/api-keys', 'api_keys:read'],
    ['DELETE', '/api/v1/api-keys/k1', 'api_keys:write'],
    ['GET', '/api/v1/roles', 'roles:read'],
    ['GET', '/api/v1/users/u1/environment-roles', 'roles:read'],
    ['GET', '/api/v1/users/u1/environment-roles/r1', 'roles:read'],
    ['PUT', '/api/v1/users/u1/environment-roles/r1', 'roles:write'],
    ['DELETE', '/api/v1/users/u1/environment-roles/r1', 'roles:write'],
    ['GET', '/api/v1/apps', 'apps:read'],
    ['POST', '/api/v1/apps', 'apps:write'],
    ['GET', '/api/v1/apps/a1/blueprint', 'apps:read'],
    ['GET', '/api/v1/apis', 'apis:read'],
    ['POST', '/api/v1/apis', 'apis:write'],
    ['GET', '/api/v1/apis/a1', 'apis:read'],
    ['PATCH', '/api/v1/apis/a1', 'apis:write'],
    ['DELETE', '/api/v1/apis/a1', 'apis:write'],
    ['POST', '/api/v1/support-sessions', 'support:write'],
]);

/*
| Organizations
*/

it('creates an organization with its owner and its parent, in one step', function (): void {
    [$key, $row] = tenancyKey([EnvironmentApiScope::OrganizationsWrite]);
    $ownerId = tenancyUser('ada@acme.test');
    $parent = tenancyOrg('Reseller');

    $id = $this->withToken($key)->postJson('/api/v1/organizations', [
        'name' => 'Acme',
        'parent_id' => $parent->id,
        'owner_user_id' => $ownerId,
    ])->assertCreated()
        ->assertJsonPath('data.slug', 'acme')
        ->assertJsonPath('data.parent_id', $parent->id)
        ->json('data.id');

    expect(app(Memberships::class)->activeRole($id, $ownerId))->toBe(MembershipRole::Owner);

    // The framework recorded the create as the system; the trail says the key did it.
    $created = AuditEntry::query()->where('action', 'organization.created')->where('target_id', $id)->sole();

    expect($created->actor_type)->toBe(ActorType::Service)
        ->and($created->actor_id)->toBe($row->id)
        ->and($created->context['environment_api_key'])->toBe($row->id);

    // A derived slug is walked to a free one — which is why a retrying caller sends its own.
    $this->withToken($key)->postJson('/api/v1/organizations', ['name' => 'Acme'])
        ->assertCreated()->assertJsonPath('data.slug', 'acme-2');
});

it('refuses an owner or a parent from another environment, and creates nothing', function (): void {
    [$key] = tenancyKey([EnvironmentApiScope::OrganizationsWrite]);

    $foreignUser = inOtherEnvironment(fn (): string => tenancyUser('eve@other.test'));
    $foreignOrg = inOtherEnvironment(fn (): Organization => tenancyOrg('Elsewhere'));

    $this->withToken($key)->postJson('/api/v1/organizations', ['name' => 'Acme', 'slug' => 'acme', 'owner_user_id' => $foreignUser])
        ->assertStatus(422)->assertJsonPath('error', 'user_not_found');

    $this->withToken($key)->postJson('/api/v1/organizations', ['name' => 'Acme', 'slug' => 'acme', 'parent_id' => $foreignOrg->id])
        ->assertStatus(422)->assertJsonPath('error', 'parent_not_found');

    expect(app(Organizations::class)->bySlug('acme'))->toBeNull();
});

it('renames through the framework, announcing organization.updated and keeping the old name on the trail', function (): void {
    [$key, $row] = tenancyKey([EnvironmentApiScope::OrganizationsWrite]);
    $org = tenancyOrg('Acme');

    $this->withToken($key)->patchJson("/api/v1/organizations/{$org->id}", ['name' => 'Acme Group', 'slug' => 'acme-group'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Acme Group')
        ->assertJsonPath('data.slug', 'acme-group');

    $event = Event::query()->where('type', 'organization.updated')->where('organization_id', $org->id)->sole();
    expect($event->payload['changed'])->toEqualCanonicalizing(['name', 'slug']);

    $renamed = auditFor('organization.renamed');
    expect($renamed?->context)->toMatchArray(['from' => 'Acme', 'to' => 'Acme Group', 'slug_from' => 'acme', 'slug_to' => 'acme-group'])
        ->and($renamed?->actor_type)->toBe(ActorType::Service)
        ->and($renamed?->actor_id)->toBe($row->id)
        ->and(auditFor('organization.updated')?->actor_id)->toBe($row->id);

    // Taken slugs are refused; a no-op changes nothing and records nothing.
    tenancyOrg('Taken');
    $this->withToken($key)->patchJson("/api/v1/organizations/{$org->id}", ['slug' => 'taken'])
        ->assertStatus(422)->assertJsonPath('error', 'slug_taken');

    $this->withToken($key)->patchJson("/api/v1/organizations/{$org->id}", ['name' => 'Acme Group'])->assertOk();
    expect(AuditEntry::query()->where('action', 'organization.renamed')->count())->toBe(1);
});

it('archives an organization on DELETE, idempotently, and never one from another environment', function (): void {
    [$key, $row] = tenancyKey([EnvironmentApiScope::OrganizationsWrite, EnvironmentApiScope::OrganizationsRead]);
    $org = tenancyOrg('Acme');

    $this->withToken($key)->deleteJson("/api/v1/organizations/{$org->id}")
        ->assertOk()->assertJsonPath('data.status', 'deleted');

    $this->withToken($key)->deleteJson("/api/v1/organizations/{$org->id}")
        ->assertOk()->assertJsonPath('data.status', 'deleted');

    expect(AuditEntry::query()->where('action', 'organization.archived')->count())->toBe(1)
        ->and(auditFor('organization.archived')?->actor_type)->toBe(ActorType::Service)
        ->and(auditFor('organization.archived')?->actor_id)->toBe($row->id)
        ->and(Event::query()->where('type', 'organization.deleted')->where('organization_id', $org->id)->exists())->toBeTrue();

    $foreign = inOtherEnvironment(fn (): Organization => tenancyOrg('Elsewhere'));

    foreach (['patchJson', 'deleteJson', 'getJson'] as $verb) {
        $this->withToken($key)->{$verb}("/api/v1/organizations/{$foreign->id}", ['name' => 'Mine now'])->assertNotFound();
    }

    expect(inOtherEnvironment(fn () => Organization::query()->find($foreign->id)))
        ->status->toBe(OrganizationStatus::Active)
        ->name->toBe('Elsewhere');
});

/*
| Members and ownership
*/

it('adds, lists, re-tiers and removes members', function (): void {
    [$key] = tenancyKey([EnvironmentApiScope::MembersRead, EnvironmentApiScope::MembersWrite]);
    $ownerId = tenancyUser('owner@acme.test');
    $org = tenancyOrg('Acme', $ownerId);
    $bob = tenancyUser('bob@acme.test', 'Bob');

    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/members", ['user_id' => $bob, 'role' => 'admin'])
        ->assertCreated()
        ->assertJsonPath('data.user_id', $bob)
        ->assertJsonPath('data.role', 'admin')
        ->assertJsonPath('data.email', 'bob@acme.test');

    // Idempotent for the same tier; a different tier is a conflict, not a silent change.
    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/members", ['user_id' => $bob, 'role' => 'admin'])
        ->assertOk();
    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/members", ['user_id' => $bob, 'role' => 'member'])
        ->assertStatus(409)->assertJsonPath('error', 'already_member');

    // Ownership is never assigned.
    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/members", ['user_id' => tenancyUser('carol@acme.test'), 'role' => 'owner'])
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    $this->withToken($key)->patchJson("/api/v1/organizations/{$org->id}/members/{$bob}", ['role' => 'owner'])
        ->assertStatus(422);

    $this->withToken($key)->getJson("/api/v1/organizations/{$org->id}/members")
        ->assertOk()->assertJsonCount(2, 'data');

    $this->withToken($key)->patchJson("/api/v1/organizations/{$org->id}/members/{$bob}", ['role' => 'member'])
        ->assertOk()->assertJsonPath('data.role', 'member');

    $this->withToken($key)->deleteJson("/api/v1/organizations/{$org->id}/members/{$bob}")->assertNoContent();
    $this->withToken($key)->deleteJson("/api/v1/organizations/{$org->id}/members/{$ownerId}")
        ->assertStatus(409)->assertJsonPath('error', 'last_owner');

    expect(app(Memberships::class)->of($org->id, $bob))->toBeNull()
        ->and(Event::query()->where('type', 'membership.deleted')->where('organization_id', $org->id)->count())->toBe(1);
});

it('never reads or changes a member through another organization', function (): void {
    [$key] = tenancyKey([EnvironmentApiScope::MembersRead, EnvironmentApiScope::MembersWrite, EnvironmentApiScope::RolesRead]);
    $mine = tenancyOrg('Mine');
    $theirs = tenancyOrg('Theirs');
    $bob = tenancyUser('bob@theirs.test');
    app(Memberships::class)->add($theirs->id, $bob, MembershipRole::Admin);

    $this->withToken($key)->patchJson("/api/v1/organizations/{$mine->id}/members/{$bob}", ['role' => 'member'])->assertNotFound();
    $this->withToken($key)->deleteJson("/api/v1/organizations/{$mine->id}/members/{$bob}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/organizations/{$mine->id}/members/{$bob}/roles")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/organizations/{$mine->id}/members")->assertOk()->assertJsonCount(0, 'data');

    expect(app(Memberships::class)->of($theirs->id, $bob)?->role)->toBe(MembershipRole::Admin);

    // A user of another environment is no user here.
    $foreign = inOtherEnvironment(fn (): string => tenancyUser('eve@other.test'));
    $this->withToken($key)->postJson("/api/v1/organizations/{$mine->id}/members", ['user_id' => $foreign])
        ->assertStatus(422)->assertJsonPath('error', 'user_not_found');
});

it('transfers ownership with the framework lifecycle, and gives an ownerless organization its first owner', function (): void {
    [$key, $row] = tenancyKey([EnvironmentApiScope::OrganizationsWrite]);
    $ada = tenancyUser('ada@acme.test');
    $bob = tenancyUser('bob@acme.test');
    $org = tenancyOrg('Acme', $ada);
    app(Memberships::class)->add($org->id, $bob, MembershipRole::Member);

    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/transfer-ownership", ['user_id' => $bob])
        ->assertOk()->assertJsonPath('data.role', 'owner');

    expect(app(Memberships::class)->owners($org->id))->toBe([$bob])
        ->and(app(Memberships::class)->activeRole($org->id, $ada))->toBe(MembershipRole::Admin);

    // The framework records a hand-over as the outgoing owner's act; the key that asked for
    // it is on the entry beside them.
    $handover = AuditEntry::query()->where('organization_id', $org->id)->where('context->environment_api_key', $row->id)
        ->where('actor_id', $ada)->first();
    expect($handover)->not->toBeNull();

    // Handing it to the owner, or to a stranger, is refused with the reason.
    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/transfer-ownership", ['user_id' => $bob])
        ->assertStatus(409)->assertJsonPath('error', 'already_owner');
    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/transfer-ownership", ['user_id' => tenancyUser('stranger@acme.test')])
        ->assertStatus(422)->assertJsonPath('error', 'not_a_member');

    // An organization created without an owner gets its first one.
    $bare = tenancyOrg('Bare');
    app(Memberships::class)->add($bare->id, $ada, MembershipRole::Member);

    $this->withToken($key)->postJson("/api/v1/organizations/{$bare->id}/transfer-ownership", ['user_id' => $ada])->assertOk();

    expect(app(Memberships::class)->owners($bare->id))->toBe([$ada])
        ->and(auditFor('organization.ownership_transferred', $bare->id)?->actor_id)->toBe($row->id)
        ->and(auditFor('organization.ownership_transferred', $bare->id)?->actor_type)->toBe(ActorType::Service);
});

/*
| Invitations
*/

it('invites with the app\'s manifest roles by key, a way back to the app, and lists what it parked', function (): void {
    [$key] = tenancyKey([EnvironmentApiScope::InvitationsRead, EnvironmentApiScope::InvitationsWrite]);
    $org = tenancyOrg('Acme');
    $app = tenancyApp();
    $viewer = tenancyAppRole($app, 'viewer');

    $invited = $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/invitations", [
        'email' => 'new@acme.test',
        'role' => 'member',
        'roles' => ['viewer'],
        'client_id' => $app->client_id,
        'return_to' => 'https://tax.example/welcome',
    ])->assertCreated()
        ->assertJsonPath('data.email', 'new@acme.test')
        ->assertJsonPath('data.roles', [$viewer->id])
        ->assertJsonPath('data.client_id', $app->client_id)
        ->assertJsonPath('data.return_to', 'https://tax.example/welcome')
        ->json('data.id');

    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('new@acme.test') && $mail->inviter === 'Tax');

    $this->withToken($key)->getJson("/api/v1/organizations/{$org->id}/invitations")
        ->assertOk()
        ->assertJsonPath('data.0.id', $invited)
        ->assertJsonPath('data.0.roles', [$viewer->id]);

    // A return address off the app's registered origins is refused, and nothing is sent.
    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/invitations", [
        'email' => 'other@acme.test',
        'client_id' => $app->client_id,
        'return_to' => 'https://evil.example/',
    ])->assertStatus(422)->assertJsonPath('error', 'return_to_not_registered');
});

it('refuses a staff role on an invitation instead of quietly dropping it', function (): void {
    [$key] = tenancyKey([EnvironmentApiScope::InvitationsWrite]);
    $org = tenancyOrg('Acme');
    $app = tenancyApp();
    tenancyAppRole($app, 'support', tenantAssignable: false);

    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/invitations", [
        'email' => 'new@acme.test',
        'roles' => ['support'],
        'client_id' => $app->client_id,
    ])->assertStatus(422)
        ->assertJsonPath('error', 'role_not_assignable')
        ->assertJsonPath('message', 'The role [Support] is a staff role. Staff roles are never granted by invitation — grant it to the member after they join.');

    expect(Invitation::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('re-sends on a fresh link and withdraws, only within the invitation\'s own organization', function (): void {
    [$key] = tenancyKey([EnvironmentApiScope::InvitationsRead, EnvironmentApiScope::InvitationsWrite]);
    $mine = tenancyOrg('Mine');
    $theirs = tenancyOrg('Theirs');

    $first = $this->withToken($key)->postJson("/api/v1/organizations/{$mine->id}/invitations", ['email' => 'a@mine.test'])
        ->assertCreated()->json('data.id');
    $theirInvitation = $this->withToken($key)->postJson("/api/v1/organizations/{$theirs->id}/invitations", ['email' => 'b@theirs.test'])
        ->assertCreated()->json('data.id');

    $second = $this->withToken($key)->postJson("/api/v1/organizations/{$mine->id}/invitations/{$first}/resend")
        ->assertOk()->json('data.id');

    expect($second)->not->toBe($first)
        ->and(Invitation::query()->find($first)?->status)->toBe(InvitationStatus::Revoked);

    // Another organization's invitation, reached through mine: not found, and untouched.
    $this->withToken($key)->deleteJson("/api/v1/organizations/{$mine->id}/invitations/{$theirInvitation}")->assertNotFound();
    $this->withToken($key)->postJson("/api/v1/organizations/{$mine->id}/invitations/{$theirInvitation}/resend")->assertNotFound();
    expect(Invitation::query()->find($theirInvitation)?->status)->toBe(InvitationStatus::Pending);

    $this->withToken($key)->deleteJson("/api/v1/organizations/{$mine->id}/invitations/{$second}")->assertNoContent();
    $this->withToken($key)->deleteJson("/api/v1/organizations/{$mine->id}/invitations/{$second}")
        ->assertStatus(409)->assertJsonPath('error', 'not_pending');
});

/*
| Roles
*/

it('lists the role catalogue with each role\'s permissions and whether tenants may grant it', function (): void {
    [$key] = tenancyKey([EnvironmentApiScope::RolesRead]);
    $app = tenancyApp();
    tenancyAppRole($app, 'viewer', permissions: ['parcels:read']);
    tenancyAppRole($app, 'support', tenantAssignable: false, permissions: ['support:impersonate']);

    $roles = collect($this->withToken($key)->getJson('/api/v1/roles?client_id='.$app->client_id)->assertOk()->json('data'))->keyBy('key');

    expect($roles->keys()->sort()->values()->all())->toBe(['support', 'viewer'])
        ->and($roles['support']['tenant_assignable'])->toBeFalse()
        ->and($roles['support']['permissions'])->toBe(['support:impersonate'])
        ->and($roles['viewer']['tenant_assignable'])->toBeTrue();
});

it('grants a staff role inside one organization with the environment\'s authority, while the tenant plane still refuses it', function (): void {
    [$key] = tenancyKey([EnvironmentApiScope::RolesRead, EnvironmentApiScope::RolesWrite]);
    $org = tenancyOrg('Acme');
    $lead = tenancyUser('lead@vendor.test');
    app(Memberships::class)->add($org->id, $lead, MembershipRole::Member);
    $app = tenancyApp();
    $support = tenancyAppRole($app, 'support', tenantAssignable: false);

    // By manifest key, with the app that declared it.
    $this->withToken($key)->putJson("/api/v1/organizations/{$org->id}/members/{$lead}/roles/support?client_id={$app->client_id}")
        ->assertOk()
        ->assertJsonPath('data.role_id', $support->id)
        ->assertJsonPath('data.tenant_assignable', false)
        ->assertJsonPath('data.organization_id', $org->id);

    $this->withToken($key)->getJson("/api/v1/organizations/{$org->id}/members/{$lead}/roles")
        ->assertOk()->assertJsonPath('data.0.role_id', $support->id);

    // The tenant plane's guard is untouched: an organization's own administrator still
    // cannot hand out the same role.
    $colleague = tenancyUser('colleague@acme.test');
    app(Memberships::class)->add($org->id, $colleague, MembershipRole::Member);

    expect(fn () => app(GrantAccessRole::class)->grantAsTenant($org->id, $colleague, $support->id))
        ->toThrow(RoleNotTenantAssignable::class);

    // Idempotent both ways.
    $this->withToken($key)->putJson("/api/v1/organizations/{$org->id}/members/{$lead}/roles/{$support->id}")->assertOk();
    $this->withToken($key)->deleteJson("/api/v1/organizations/{$org->id}/members/{$lead}/roles/{$support->id}")->assertNoContent();
    $this->withToken($key)->deleteJson("/api/v1/organizations/{$org->id}/members/{$lead}/roles/{$support->id}")->assertNoContent();

    expect(app(Roles::class)->assignmentsForSubject($org->id, $lead))->toBe([]);
});

it('never grants another organization\'s role, even to a real member', function (): void {
    [$key] = tenancyKey([EnvironmentApiScope::RolesWrite]);
    $mine = tenancyOrg('Mine');
    $theirs = tenancyOrg('Theirs');
    $bob = tenancyUser('bob@mine.test');
    app(Memberships::class)->add($mine->id, $bob, MembershipRole::Member);
    $theirRole = app(Roles::class)->define($theirs->id, 'Their auditor');

    $this->withToken($key)->putJson("/api/v1/organizations/{$mine->id}/members/{$bob}/roles/{$theirRole->id}")
        ->assertStatus(422)->assertJsonPath('error', 'role_not_assignable');

    expect(app(Roles::class)->assignmentsForSubject($mine->id, $bob))->toBe([]);
});

it('grants a role everywhere to staff, including one app\'s own role, and takes it back', function (): void {
    [$key] = tenancyKey([EnvironmentApiScope::RolesRead, EnvironmentApiScope::RolesWrite]);
    $agent = tenancyUser('agent@vendor.test');
    $app = tenancyApp();
    $support = tenancyAppRole($app, 'support', tenantAssignable: false);

    $this->withToken($key)->getJson("/api/v1/users/{$agent}/environment-roles/{$support->id}")->assertNotFound();

    $this->withToken($key)->putJson("/api/v1/users/{$agent}/environment-roles/{$support->id}")
        ->assertOk()->assertJsonPath('data.organization_id', null)->assertJsonPath('data.client_id', $app->client_id);

    $this->withToken($key)->getJson("/api/v1/users/{$agent}/environment-roles/support?client_id={$app->client_id}")->assertOk();
    $this->withToken($key)->getJson("/api/v1/users/{$agent}/environment-roles")->assertOk()->assertJsonCount(1, 'data');

    expect(app(Roles::class)->everywhereFor($agent))->toBe([$support->id])
        ->and(auditFor('role.assigned_everywhere')?->actor_type)->toBe(ActorType::Service);

    // An organization's own role never goes environment-wide.
    $orgRole = app(Roles::class)->define(tenancyOrg('Acme')->id, 'Acme only');
    $this->withToken($key)->putJson("/api/v1/users/{$agent}/environment-roles/{$orgRole->id}")
        ->assertStatus(422)->assertJsonPath('error', 'role_not_assignable');

    $this->withToken($key)->deleteJson("/api/v1/users/{$agent}/environment-roles/{$support->id}")->assertNoContent();
    expect(app(Roles::class)->everywhereFor($agent))->toBe([]);

    // A user of another environment is not found here.
    $foreign = inOtherEnvironment(fn (): string => tenancyUser('eve@other.test'));
    $this->withToken($key)->putJson("/api/v1/users/{$foreign}/environment-roles/{$support->id}")->assertNotFound();
});

/*
| Apps
*/

it('registers an app, shows its secret once, and exports a blueprint that carries no credential', function (): void {
    [$key, $row] = tenancyKey([EnvironmentApiScope::AppsRead, EnvironmentApiScope::AppsWrite]);

    $created = $this->withToken($key)->postJson('/api/v1/apps', [
        'name' => 'Tax web',
        'type' => 'web',
        'redirect_uris' => ['https://staging.tax.example/callback'],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'web')
        ->assertJsonPath('data.client_type', 'confidential')
        ->json('data');

    expect($created['client_secret'])->toBeString()->not->toBe('')
        ->and(auditFor('app.created')?->actor_type)->toBe(ActorType::Service)
        ->and(auditFor('app.created')?->actor_id)->toBe($row->id);

    $listed = $this->withToken($key)->getJson('/api/v1/apps')->assertOk()->json('data.0');
    expect($listed)->not->toHaveKey('client_secret');

    // By client id as well as by id.
    $blueprint = $this->withToken($key)->getJson("/api/v1/apps/{$created['client_id']}/blueprint")
        ->assertOk()->assertJsonPath('data.kind', 'cbox-id.client-blueprint')->json('data');

    expect(json_encode($blueprint))->not->toContain($created['client_id'])
        ->and($blueprint)->not->toHaveKeys(['client_id', 'client_secret', 'jwks', 'organization_id']);

    // Promote: the same app again, with production's redirect URIs.
    $promoted = $this->withToken($key)->postJson('/api/v1/apps', [
        'blueprint' => $blueprint,
        'redirect_uris' => ['https://tax.example/callback'],
    ])->assertCreated()->json('data');

    expect($promoted['client_id'])->not->toBe($created['client_id'])
        ->and($promoted['redirect_uris'])->toBe(['https://tax.example/callback'])
        ->and($promoted['name'])->toBe('Tax web');

    // A blueprint carrying a credential is refused, never half-read.
    $this->withToken($key)->postJson('/api/v1/apps', ['blueprint' => $blueprint + ['client_secret' => 'x']])
        ->assertStatus(422)->assertJsonPath('error', 'invalid_client_metadata');
});

/*
| APIs
*/

it('registers, changes and deletes an API, with its scopes as a complete set', function (): void {
    [$key, $row] = tenancyKey([EnvironmentApiScope::ApisRead, EnvironmentApiScope::ApisWrite]);

    $id = $this->withToken($key)->postJson('/api/v1/apis', [
        'identifier' => 'https://api.tax.example',
        'name' => 'Tax API',
        'scopes' => [['key' => 'returns:read'], ['key' => 'returns:write', 'tenant_requestable' => false]],
    ])->assertCreated()
        ->assertJsonPath('data.organization_id', null)
        ->assertJsonCount(2, 'data.scopes')
        ->json('data.id');

    $this->withToken($key)->postJson('/api/v1/apis', ['identifier' => 'https://api.tax.example', 'name' => 'Again'])
        ->assertStatus(422)->assertJsonPath('error', 'invalid_api');

    $this->withToken($key)->patchJson("/api/v1/apis/{$id}", [
        'name' => 'Tax',
        'scopes' => [['key' => 'returns:read', 'description' => 'Read returns']],
    ])->assertOk()
        ->assertJsonPath('data.name', 'Tax')
        ->assertJsonPath('data.scopes', [['key' => 'returns:read', 'description' => 'Read returns', 'tenant_requestable' => true]]);

    // One entry per change, in the console's shape (ApisConsoleTest compares the two doors).
    // toEqual, not toBe: MySQL's JSON column stores an object's keys in its own order.
    expect(auditFor('api.updated')?->context['changes'] ?? null)->toEqual(['name' => ['from' => 'Tax API', 'to' => 'Tax']])
        ->and(auditFor('api.scope_removed')?->context['scope'] ?? null)->toBe('returns:write')
        ->and(auditFor('api.scope_defined')?->context['from'] ?? null)->toEqual(['description' => null, 'tenant_requestable' => true])
        ->and(auditFor('api.created')?->actor_id)->toBe($row->id)
        ->and(auditFor('api.created')?->target_id)->toBe('https://api.tax.example');

    $this->withToken($key)->getJson('/api/v1/apis')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($key)->deleteJson("/api/v1/apis/{$id}")->assertNoContent();
    $this->withToken($key)->getJson("/api/v1/apis/{$id}")->assertNotFound();
});

/*
| Customer API keys
*/

it('lists an organization\'s customer API keys and revokes one as the management key', function (): void {
    [$key, $row] = tenancyKey([EnvironmentApiScope::ApiKeysRead, EnvironmentApiScope::ApiKeysWrite]);
    $app = tenancyApp(changes: ['apiKeyPrefix' => 'tax_live']);
    $holder = tenancyUser('holder@acme.test');
    $org = tenancyOrg('Acme', $holder);
    $other = tenancyOrg('Other', tenancyUser('other@other.test'));

    $issued = app(CustomerApiKeys::class)->issue(new NewCustomerApiKey($org->id, $holder, $app->client_id, name: 'CI'));

    $this->withToken($key)->getJson("/api/v1/organizations/{$org->id}/api-keys")
        ->assertOk()
        ->assertJsonPath('data.0.id', $issued->key->id)
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonMissing(['token_hash' => $issued->key->token_hash]);

    $this->withToken($key)->getJson("/api/v1/organizations/{$other->id}/api-keys")->assertOk()->assertJsonCount(0, 'data');

    $this->withToken($key)->deleteJson("/api/v1/api-keys/{$issued->key->id}")->assertNoContent();
    $this->withToken($key)->deleteJson("/api/v1/api-keys/{$issued->key->id}")->assertNoContent();

    $revoked = auditFor('api_key.revoked');
    expect($revoked?->actor_type)->toBe(ActorType::Service)
        ->and($revoked?->actor_id)->toBe($row->id)
        ->and(AuditEntry::query()->where('action', 'api_key.revoked')->count())->toBe(1);

    $this->withToken($key)->deleteJson('/api/v1/api-keys/01JUNKJUNKJUNKJUNKJUNKJUNK')->assertNotFound();
});

/*
| Support sessions
*/

it('starts a support session for staff holding the app\'s support:impersonate everywhere, and mints its first code', function (): void {
    [$key] = tenancyKey([EnvironmentApiScope::SupportWrite]);
    $app = tenancyApp();
    $support = tenancyAppRole($app, 'support', tenantAssignable: false, permissions: ['support:impersonate']);
    $agent = tenancyUser('agent@vendor.test');
    $customer = tenancyUser('customer@acme.test');
    $org = tenancyOrg('Acme', $customer);

    $body = [
        'user_id' => $customer,
        'organization_id' => $org->id,
        'client_id' => $app->client_id,
        'actor_user_id' => $agent,
        'reason' => 'Ticket 4411',
        'ttl_minutes' => 30,
        'redirect_uri' => 'https://tax.example/callback',
        'code_challenge' => str_repeat('a', 43),
    ];

    // Not staff yet: the framework refuses, and says why.
    $this->withToken($key)->postJson('/api/v1/support-sessions', $body)
        ->assertForbidden()->assertJsonPath('error', 'not_permitted');

    app(Roles::class)->assignEverywhere($agent, $support->id);

    $this->withToken($key)->postJson('/api/v1/support-sessions', $body)
        ->assertCreated()
        ->assertJsonPath('data.user_id', $customer)
        ->assertJsonPath('data.act.sub', $agent)
        ->assertJsonPath('data.redirect_uri', 'https://tax.example/callback')
        ->assertJsonStructure(['data' => ['code', 'expires_at']]);

    expect(Event::query()->where('type', 'support_session.started')->where('organization_id', $org->id)->exists())->toBeTrue();

    // The key is not a person: there is no session without one named.
    $this->withToken($key)->postJson('/api/v1/support-sessions', array_diff_key($body, ['actor_user_id' => true]))
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed');

    // An organization of another environment is none of this key's business.
    $foreign = inOtherEnvironment(fn (): Organization => tenancyOrg('Elsewhere'));
    $this->withToken($key)->postJson('/api/v1/support-sessions', ['organization_id' => $foreign->id] + $body)
        ->assertStatus(422)->assertJsonPath('error', 'organization_not_found');
});

/*
| Attribution
*/

it('attributes to the key only what happened during its request', function (): void {
    [$key, $row] = tenancyKey([EnvironmentApiScope::OrganizationsWrite]);

    $this->withToken($key)->postJson('/api/v1/organizations', ['name' => 'Acme', 'slug' => 'acme'])->assertCreated();

    // After the response the key is gone: the next thing this process does is not its act.
    $later = app(Organizations::class)->create(new NewOrganization('Later', 'later'));
    $entry = AuditEntry::query()->where('action', 'organization.created')->where('target_id', $later->id)->sole();

    expect($entry->actor_type)->toBe(ActorType::System)
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->context)->not->toHaveKey('environment_api_key')
        ->and(AuditEntry::query()->where('actor_id', $row->id)->where('action', 'organization.created')->count())->toBe(1);

    // And the activity log names the key rather than printing its id.
    $byKey = AuditEntry::query()->where('actor_id', $row->id)->get();
    expect(app(AuditNames::class)->for($byKey)[$row->id] ?? null)->toBe('Management key "Tenancy worker"');
});

/*
| POST /oauth/api-keys/verify — documented in environment.yaml under its own server
*/

it('answers key verification in the documented shape, which the contract gate checks', function (): void {
    $registered = app(ClientRegistry::class)->register(new NewClient(
        name: 'Tax API',
        type: ClientType::Confidential,
        grantTypes: ['client_credentials'],
        apiKeyPrefix: 'tax_live',
    ));
    $holder = tenancyUser('holder@acme.test');
    $org = tenancyOrg('Acme', $holder);
    $issued = app(CustomerApiKeys::class)->issue(new NewCustomerApiKey($org->id, $holder, $registered->client->client_id));

    $basic = base64_encode($registered->client->client_id.':'.$registered->secret);

    $this->withHeader('Authorization', 'Basic '.$basic)
        ->postJson('/oauth/api-keys/verify', ['key' => $issued->plaintext])
        ->assertOk()
        ->assertJsonPath('active', true)
        ->assertJsonPath('sub', $holder)
        ->assertJsonPath('org', $org->id)
        ->assertJsonPath('org_role', 'owner');

    $this->withHeader('Authorization', 'Basic '.$basic)
        ->postJson('/oauth/api-keys/verify', ['key' => 'tax_live_nothing'])
        ->assertOk()->assertExactJson(['active' => false]);

    $this->withHeader('Authorization', 'Basic '.base64_encode($registered->client->client_id.':wrong'))
        ->postJson('/oauth/api-keys/verify', ['key' => $issued->plaintext])
        ->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
});
