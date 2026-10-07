<?php

declare(strict_types=1);

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\UpdateRole;
use App\Mail\AdminAssignedPasswordMail;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Idempotency\IdempotencyRecord;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\Federation\Contracts\DnsResolver;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Testing\FakeDnsResolver;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Users, organizations, members, roles and permissions, as actions.
|--------------------------------------------------------------------------
|
| The environment's people and tenants: the management API's long-standing endpoints and
| the console's own writes, now the same actions with the same rules and the same trail —
| and every one an MCP tool. Every request here is a tenant environment's, on its own host.
*/

beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    Mail::fake();
});

/**
 * A provisioned tenant environment this test's host resolves to, administered by its owner.
 *
 * @return array{environment: Environment, ownerId: string}
 */
function uoaTenant(): array
{
    multiTenantDeployment();
    $tenant = provisionAccount();

    serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));

    return ['environment' => $tenant['environment']->refresh(), 'ownerId' => $tenant['subjectId']];
}

/**
 * A management key for $environment holding exactly $scopes, optionally under a step-up
 * policy owned by $owner.
 *
 * @param  list<string>  $scopes
 * @param  array<string, mixed>|null  $policy
 * @return array{0: string, 1: string} the key, and its id
 */
function uoaKey(Environment $environment, array $scopes, ?string $owner = null, ?array $policy = null): array
{
    $issued = app(EnvironmentApiKeys::class)->issue($environment->id, 'People worker', $scopes, null, new KeyProvenance(
        createdByType: $owner === null ? null : 'organization_member',
        createdById: $owner,
        stepUpPolicy: $policy,
    ));

    return [$issued->plaintext, (string) $issued->key->id];
}

function uoaUser(string $email, ?string $name = null): string
{
    return app(Subjects::class)->create($email, $name ?? Str::before($email, '@'))->id;
}

function uoaOrg(string $name = 'Tenant Co'): Organization
{
    return app(Organizations::class)->create(new NewOrganization($name, Str::slug($name).'-'.Str::lower(Str::random(4))));
}

/** Run in ANOTHER environment than the one every request here resolves to. */
function inUoaOtherEnvironment(Closure $callback): mixed
{
    return app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), $callback);
}

/** The newest entry of $action on the current environment's trails. */
function uoaAudit(string $action): ?AuditEntry
{
    return AuditEntry::query()->where('action', $action)->orderByDesc('id')->first();
}

const UOA_ALL = [
    'users:read', 'users:write', 'organizations:read', 'organizations:write', 'members:read', 'members:write',
    'invitations:read', 'invitations:write', 'roles:read', 'roles:write', 'role_definitions:write',
    'api_keys:read', 'api_keys:write', 'support:write',
];

/*
|--------------------------------------------------------------------------
| The read side an agent needs: finding somebody
|--------------------------------------------------------------------------
*/

it('finds a user by exact address, by a fragment, and by status, a page at a time', function (): void {
    ['environment' => $environment] = uoaTenant();
    [$key] = uoaKey($environment, ['users:read']);

    $ada = uoaUser('ada_lovelace@acme.test', 'Ada Lovelace');
    uoaUser('adalovelace@acme.test', 'Not Ada');
    $grace = uoaUser('grace@acme.test', 'Grace Hopper');
    app(Subjects::class)->deactivate($grace);

    // Exact, and case-insensitive: an address is.
    $this->withToken($key)->getJson('/api/v1/users?email=ADA_Lovelace@acme.test')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ada);

    // A literal underscore is a literal: `ada_` does not match `adalovelace`.
    $this->withToken($key)->getJson('/api/v1/users?q=ada_')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ada);

    // By name too, and narrowed by status.
    $this->withToken($key)->getJson('/api/v1/users?q=hopper')->assertOk()->assertJsonPath('data.0.id', $grace);
    $this->withToken($key)->getJson('/api/v1/users?status=disabled')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'disabled');
    $this->withToken($key)->getJson('/api/v1/users?status=banished')
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

    // A page at a time, by cursor.
    $first = $this->withToken($key)->getJson('/api/v1/users?limit=1')->assertOk()->assertJsonPath('meta.has_more', true);
    $this->withToken($key)->getJson('/api/v1/users?limit=1&after='.$first->json('meta.next_cursor'))
        ->assertOk()->assertJsonCount(1, 'data');
});

it('lists organizations by search and status, and an organization\'s members with their names', function (): void {
    ['environment' => $environment] = uoaTenant();
    [$key] = uoaKey($environment, ['organizations:read', 'members:read']);

    $acme = uoaOrg('Acme Shipping');
    $beta = uoaOrg('Beta Freight');
    app(Organizations::class)->suspend($beta->id, 'someone');
    app(Memberships::class)->add($acme->id, uoaUser('ada@acme.test', 'Ada'), MembershipRole::Admin);

    $this->withToken($key)->getJson('/api/v1/organizations?q=shipping')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $acme->id);
    $this->withToken($key)->getJson('/api/v1/organizations?status=suspended')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $beta->id);

    $this->withToken($key)->getJson("/api/v1/organizations/{$acme->id}/members")
        ->assertOk()
        ->assertJsonPath('data.0.email', 'ada@acme.test')
        ->assertJsonPath('data.0.name', 'Ada')
        ->assertJsonPath('data.0.role', 'admin');
});

/*
|--------------------------------------------------------------------------
| Every write, from the API and from the console: the same entry, as whoever asked
|--------------------------------------------------------------------------
*/

it('renames an organization from the API and the console with the same entry, naming who asked', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = uoaTenant();
    [$key, $keyId] = uoaKey($environment, ['organizations:write']);
    $org = uoaOrg('Acme');

    $this->withToken($key)->patchJson("/api/v1/organizations/{$org->id}", ['name' => 'Acme Group', 'metadata' => ['crm_id' => '42']])
        ->assertOk()
        ->assertJsonPath('data.name', 'Acme Group')
        ->assertJsonPath('data.metadata.crm_id', '42');

    $byKey = uoaAudit('organization.renamed');

    actAsEnvironmentAdmin($ownerId, $environment->id);
    test()->from(route('environment.organizations.show', $org->id))
        ->patch(route('environment.organizations.update', $org->id), ['name' => 'Acme Holdings', 'slug' => $org->slug, 'metadata' => [['key' => 'crm_id', 'value' => '42']]])
        ->assertSessionHasNoErrors();

    $byConsole = uoaAudit('organization.renamed');

    expect($byKey?->actor_type)->toBe(ActorType::Service)
        ->and($byKey?->actor_id)->toBe($keyId)
        ->and($byConsole?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($byConsole?->actor_id)->toBe($ownerId)
        ->and([$byConsole?->organization_id, $byConsole?->target_type, $byConsole?->target_id])
        ->toBe([$byKey?->organization_id, $byKey?->target_type, $byKey?->target_id])
        ->and($byConsole?->context)->toMatchArray(['from' => 'Acme Group', 'to' => 'Acme Holdings'])
        // The metadata the console re-sent unchanged recorded nothing.
        ->and(AuditEntry::query()->where('action', 'organization.settings_updated')->count())->toBe(1);
})->group('security');

it('resets a user\'s second factor as whoever asked, from both doors', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = uoaTenant();
    [$key, $keyId] = uoaKey($environment, ['users:write']);
    $ada = uoaUser('ada@acme.test');
    $mfa = app(Mfa::class);

    $mfa->enrollTotp($ada, 'ada@acme.test');
    $this->withToken($key)->deleteJson("/api/v1/users/{$ada}/mfa")->assertNoContent();
    $byKey = uoaAudit('user.mfa_disabled');

    $mfa->enrollTotp($ada, 'ada@acme.test');
    actAsEnvironmentAdmin($ownerId, $environment->id);
    confirmEnvironmentStepUp();
    test()->from(route('environment.users.show', $ada))->post(route('environment.users.mfa', $ada))->assertSessionHasNoErrors();
    $byConsole = uoaAudit('user.mfa_disabled');

    // It used to be recorded as the person themselves turning it off.
    expect($byKey?->actor_type)->toBe(ActorType::Service)
        ->and($byKey?->actor_id)->toBe($keyId)
        ->and($byConsole?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($byConsole?->actor_id)->toBe($ownerId)
        ->and($byConsole?->target_id)->toBe($ada);
})->group('security');

it('runs the console\'s people writes through the actions', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = uoaTenant();
    actAsEnvironmentAdmin($ownerId, $environment->id);
    $org = uoaOrg('Acme');
    $ada = uoaUser('ada@acme.test');

    test()->from(route('environment.users.show', $ada))->post(route('environment.users.deactivate', $ada))->assertSessionHasNoErrors();
    expect(User::query()->find($ada)?->status)->toBe(UserStatus::Disabled);

    test()->from(route('environment.users.show', $ada))->post(route('environment.users.reactivate', $ada))->assertSessionHasNoErrors();

    assignUserToOrganization($ada, $org->id, ['role' => 'admin'])->assertSessionHasNoErrors();
    expect(app(Memberships::class)->activeRole($org->id, $ada))->toBe(MembershipRole::Admin);

    // The same tier again: idempotent to a machine, "already a member" to a person.
    assignUserToOrganization($ada, $org->id, ['role' => 'admin'])
        ->assertSessionHasErrors(['organization' => 'The user is already a member of that organization.']);

    // A suspended organization takes no new members, whichever door.
    $closed = uoaOrg('Closed');
    app(Organizations::class)->suspend($closed->id, $ownerId);
    assignUserToOrganization($ada, $closed->id)->assertSessionHasErrors('organization');
    expect(app(Memberships::class)->of($closed->id, $ada))->toBeNull();

    test()->from(route('environment.organizations.show', $org->id))
        ->post(route('environment.organizations.members.transfer-ownership', [$org->id, $ada]))
        ->assertSessionHasNoErrors();

    expect(app(Memberships::class)->owners($org->id))->toBe([$ada])
        ->and(uoaAudit('organization.ownership_transferred')?->actor_id)->toBe($ownerId);
});

/*
|--------------------------------------------------------------------------
| Scopes, and the tenant boundary
|--------------------------------------------------------------------------
*/

it('refuses each write to a key without its scope', function (string $method, string $uri, string $scope): void {
    ['environment' => $environment] = uoaTenant();
    [$key] = uoaKey($environment, array_values(array_diff(UOA_ALL, [$scope])));

    $this->withToken($key)->json($method, $uri, [])
        ->assertForbidden()
        ->assertJsonPath('message', "This key is missing the required scope: {$scope}.");
})->with([
    ['POST', '/api/v1/users', 'users:write'],
    ['PATCH', '/api/v1/users/u1', 'users:write'],
    ['POST', '/api/v1/users/u1/password', 'users:write'],
    ['DELETE', '/api/v1/users/u1/mfa', 'users:write'],
    ['GET', '/api/v1/users/u1/sessions', 'users:read'],
    ['POST', '/api/v1/organizations/o1/suspend', 'organizations:write'],
    ['POST', '/api/v1/organizations/o1/domains', 'organizations:write'],
    ['POST', '/api/v1/roles', 'role_definitions:write'],
    ['PUT', '/api/v1/roles/r1/permissions/p1', 'role_definitions:write'],
    ['POST', '/api/v1/permissions', 'role_definitions:write'],
    ['GET', '/api/v1/permissions', 'roles:read'],
    ['DELETE', '/api/v1/support-sessions/s1', 'support:write'],
])->group('security');

it('never reaches a user or an organization of another environment', function (): void {
    ['environment' => $environment] = uoaTenant();
    [$key] = uoaKey($environment, UOA_ALL);
    $mine = uoaOrg('Mine');

    $foreignUser = inUoaOtherEnvironment(fn (): string => uoaUser('eve@other.test'));
    $foreignOrg = inUoaOtherEnvironment(fn (): Organization => uoaOrg('Elsewhere'));

    $this->withToken($key)->getJson("/api/v1/users/{$foreignUser}")->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/users/{$foreignUser}", ['name' => 'Mine now'])->assertNotFound();
    $this->withToken($key)->deleteJson("/api/v1/users/{$foreignUser}")->assertNotFound();
    $this->withToken($key)->postJson("/api/v1/users/{$foreignUser}/verify")->assertNotFound();
    $this->withToken($key)->deleteJson("/api/v1/users/{$foreignUser}/sessions")->assertNotFound();

    $this->withToken($key)->postJson("/api/v1/organizations/{$foreignOrg->id}/suspend")->assertNotFound();
    $this->withToken($key)->postJson("/api/v1/organizations/{$foreignOrg->id}/members", ['user_id' => $foreignUser])->assertNotFound();
    $this->withToken($key)->postJson("/api/v1/organizations/{$foreignOrg->id}/domains", ['domain' => 'elsewhere.test'])->assertNotFound();
    $this->withToken($key)->postJson("/api/v1/organizations/{$mine->id}/members", ['user_id' => $foreignUser])
        ->assertUnprocessable()->assertJsonPath('error', 'user_not_found');

    expect(inUoaOtherEnvironment(fn () => Organization::query()->find($foreignOrg->id)?->status))->toBe(OrganizationStatus::Active)
        ->and(inUoaOtherEnvironment(fn () => User::query()->find($foreignUser)?->name))->toBe('eve');
})->group('security');

it('keeps an organization administrator to their own organization\'s roles and permissions', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $principal = new ConsoleSessionPrincipal(app(ConsoleScope::class));
    $runner = app(ActionRunner::class);

    $shared = app(Roles::class)->define(null, 'Shared');
    $theirs = app(Roles::class)->define(uoaOrg('Other')->id, 'Theirs');
    $own = app(Roles::class)->define($org->id, 'Own');

    // Somebody else's role, or the environment's own, is not theirs to change: not found.
    foreach ([$shared, $theirs] as $role) {
        expect(fn () => $runner->run(UpdateRole::class, $principal, ['id' => $role->id, 'name' => 'Mine now']))
            ->toThrow(ActionRefused::class, 'Role not found.');
    }

    $runner->run(UpdateRole::class, $principal, ['id' => $own->id, 'name' => 'Own, renamed']);

    // Nor may they define one for the environment, or for another organization.
    expect(fn () => $runner->run(CreateRole::class, $principal, ['name' => 'Everywhere', 'organization_id' => null]))
        ->toThrow(AuthorizationException::class);

    expect($shared->refresh()->name)->toBe('Shared')
        ->and($theirs->refresh()->name)->toBe('Theirs')
        ->and($own->refresh()->name)->toBe('Own, renamed');
})->group('security');

/*
|--------------------------------------------------------------------------
| Roles and permissions over the API
|--------------------------------------------------------------------------
*/

it('defines a role with its permissions, re-permissions it, and refuses an app-declared one', function (): void {
    ['environment' => $environment] = uoaTenant();
    [$key] = uoaKey($environment, ['roles:read', 'role_definitions:write']);

    $permission = $this->withToken($key)->postJson('/api/v1/permissions', ['name' => 'Invoices:Create', 'tenant_assignable' => true])
        ->assertCreated()
        ->assertJsonPath('data.name', 'invoices:create')
        ->assertJsonPath('data.manual', true)
        ->json('data.id');

    $this->withToken($key)->postJson('/api/v1/permissions', ['name' => 'invoices:create'])
        ->assertUnprocessable()->assertJsonPath('error', 'permission_taken');
    $this->withToken($key)->postJson('/api/v1/permissions', ['name' => 'not a key'])
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

    $role = $this->withToken($key)->postJson('/api/v1/roles', ['name' => 'Billing', 'permissions' => [$permission]])
        ->assertCreated()
        ->assertJsonPath('data.organization_id', null)
        ->assertJsonPath('data.permissions', ['invoices:create'])
        ->json('data.id');

    $this->withToken($key)->deleteJson("/api/v1/roles/{$role}/permissions/{$permission}")
        ->assertOk()->assertJsonPath('data.permissions', []);
    $this->withToken($key)->putJson("/api/v1/roles/{$role}/permissions/{$permission}")
        ->assertOk()->assertJsonPath('data.permissions', ['invoices:create']);

    $this->withToken($key)->getJson("/api/v1/roles/{$role}")->assertOk()->assertJsonPath('data.name', 'Billing');
    $this->withToken($key)->getJson('/api/v1/permissions?q=invoices')->assertOk()->assertJsonPath('data.0.id', $permission);

    // Deleting the permission takes it off the role first, on the trail.
    $this->withToken($key)->deleteJson("/api/v1/permissions/{$permission}")->assertNoContent();

    expect(Permission::query()->find($permission))->toBeNull()
        ->and(uoaAudit('role.permission_revoked'))->not->toBeNull();

    $declared = app(Roles::class)->define(null, 'Declared', null, null);
    $declared->forceFill(['source' => 'manifest'])->save();

    $this->withToken($key)->patchJson("/api/v1/roles/{$declared->id}", ['name' => 'Mine now'])
        ->assertForbidden()->assertJsonPath('error', 'forbidden');
});

/*
|--------------------------------------------------------------------------
| Retries and approvals
|--------------------------------------------------------------------------
*/

it('creates an organization and a user once, however often a retry asks', function (): void {
    ['environment' => $environment] = uoaTenant();
    [$key] = uoaKey($environment, ['organizations:write', 'users:write']);

    $first = $this->withToken($key)->withHeader('Idempotency-Key', 'org-1')->postJson('/api/v1/organizations', ['name' => 'Acme'])->assertCreated();
    $again = $this->withToken($key)->withHeader('Idempotency-Key', 'org-1')->postJson('/api/v1/organizations', ['name' => 'Acme'])->assertCreated();

    expect($again->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(Organization::query()->where('name', 'Acme')->count())->toBe(1);

    $this->flushHeaders();
    $user = $this->withToken($key)->withHeader('Idempotency-Key', 'user-1')->postJson('/api/v1/users', ['email' => 'ada@acme.test', 'password' => 'a-long-enough-passphrase'])->assertCreated();
    $this->withToken($key)->withHeader('Idempotency-Key', 'user-1')->postJson('/api/v1/users', ['email' => 'ada@acme.test', 'password' => 'a-long-enough-passphrase'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $user->json('data.id'));

    // The password is in neither stored record.
    expect(json_encode(IdempotencyRecord::query()->get()->toArray()))->not->toContain('a-long-enough-passphrase');
});

it('holds every takeover-class people action for the key owner\'s approval', function (string $method, string $uri, array $body): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = uoaTenant();
    [$key] = uoaKey($environment, UOA_ALL, $ownerId, ['min_danger' => 'critical', 'actions' => []]);
    $ada = uoaUser('ada@acme.test');
    $org = uoaOrg('Acme');
    app(Memberships::class)->add($org->id, $ada, MembershipRole::Member);
    $role = app(Roles::class)->define(null, 'Support');

    $uri = strtr($uri, ['{user}' => $ada, '{org}' => $org->id, '{role}' => $role->id]);
    $body = array_map(static fn (mixed $value): mixed => is_string($value) ? strtr($value, ['{user}' => $ada, '{org}' => $org->id]) : $value, $body);

    $this->withToken($key)->json($method, $uri, $body)
        ->assertStatus(202)
        ->assertJsonPath('error', 'approval_required');

    // Nothing happened while it waits.
    expect(User::query()->find($ada)?->email)->toBe('ada@acme.test')
        ->and(app(Memberships::class)->owners($org->id))->toBe([])
        ->and(app(Roles::class)->everywhereFor($ada))->toBe([]);
})->with([
    'change the recovery address' => ['PATCH', '/api/v1/users/{user}', ['email' => 'eve@evil.test']],
    'set a password' => ['POST', '/api/v1/users/{user}/password', ['password' => 'a-long-enough-passphrase', 'reason' => 'Asked']],
    'mark an address verified' => ['POST', '/api/v1/users/{user}/verify', []],
    'reset two-factor' => ['DELETE', '/api/v1/users/{user}/mfa', []],
    'hand over an organization' => ['POST', '/api/v1/organizations/{org}/transfer-ownership', ['user_id' => '{user}']],
    'grant a staff role' => ['PUT', '/api/v1/users/{user}/environment-roles/{role}', []],
])->group('security');

it('lets an ordinary write through under a critical-only policy', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = uoaTenant();
    [$key] = uoaKey($environment, UOA_ALL, $ownerId, ['min_danger' => 'critical', 'actions' => []]);
    $ada = uoaUser('ada@acme.test');
    app(Subjects::class)->deactivate($ada);

    $this->withToken($key)->postJson("/api/v1/users/{$ada}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
});

/*
|--------------------------------------------------------------------------
| Organization domains
|--------------------------------------------------------------------------
*/

it('claims, verifies and captures an organization\'s email domain, never capturing an unproven one', function (): void {
    $dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $dns);
    app()->forgetInstance(DomainVerification::class);

    ['environment' => $environment] = uoaTenant();
    [$key] = uoaKey($environment, ['organizations:read', 'organizations:write']);
    $org = uoaOrg('Acme');

    $claimed = $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/domains", ['domain' => 'Acme.test'])
        ->assertCreated()
        ->assertJsonPath('data.domain', 'acme.test')
        ->assertJsonPath('data.verified', false)
        ->json('data');

    $this->withToken($key)->putJson("/api/v1/organizations/{$org->id}/domains/{$claimed['id']}/capture", ['enabled' => true])
        ->assertUnprocessable()->assertJsonPath('error', 'domain_not_verified');
    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/domains/{$claimed['id']}/verify")
        ->assertUnprocessable()->assertJsonPath('error', 'record_not_found');

    $dns->publish($claimed['record_name'], $claimed['record_value']);

    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/domains/{$claimed['id']}/verify")
        ->assertOk()->assertJsonPath('data.verified', true);
    $this->withToken($key)->putJson("/api/v1/organizations/{$org->id}/domains/{$claimed['id']}/capture", ['enabled' => true])
        ->assertOk()->assertJsonPath('data.capture', true);

    // Another organization's domain, named under this one, is not found.
    $other = uoaOrg('Other');
    $this->withToken($key)->deleteJson("/api/v1/organizations/{$other->id}/domains/{$claimed['id']}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/organizations/{$org->id}/domains")->assertOk()->assertJsonCount(1, 'data');

    expect(uoaAudit('domain.capture_enabled'))->not->toBeNull();
})->group('security');

it('ends only a support session of this environment', function (): void {
    ['environment' => $environment] = uoaTenant();
    [$key] = uoaKey($environment, ['support:write']);

    $this->withToken($key)->deleteJson('/api/v1/support-sessions/01NOSUCHSESSION0000000000')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Users over the API
|--------------------------------------------------------------------------
*/

it('changes a user\'s address, refusing one somebody else holds, and clears its verification', function (): void {
    ['environment' => $environment] = uoaTenant();
    [$key] = uoaKey($environment, ['users:read', 'users:write']);
    $ada = uoaUser('ada@acme.test');
    uoaUser('taken@acme.test');
    app(Subjects::class)->markEmailVerified($ada, 'ada@acme.test');

    $this->withToken($key)->patchJson("/api/v1/users/{$ada}", ['email' => 'taken@acme.test'])
        ->assertUnprocessable()->assertJsonPath('error', 'email_taken');

    $this->withToken($key)->patchJson("/api/v1/users/{$ada}", ['email' => 'ada.l@acme.test', 'name' => 'Ada L'])
        ->assertOk()
        ->assertJsonPath('data.email', 'ada.l@acme.test')
        ->assertJsonPath('data.name', 'Ada L')
        ->assertJsonPath('data.email_verified_at', null);

    $this->withToken($key)->postJson("/api/v1/users/{$ada}/verify")->assertOk();
    expect(User::query()->find($ada)?->email_verified_at)->not->toBeNull();
});

it('signs a user out everywhere, and lists then ends one session', function (): void {
    ['environment' => $environment] = uoaTenant();
    [$key] = uoaKey($environment, ['users:read', 'users:write']);
    $ada = uoaUser('ada@acme.test');
    $sessions = app(SessionManager::class);
    $one = $sessions->start($ada, null, ['pwd']);
    $sessions->start($ada, null, ['pwd']);

    $this->withToken($key)->getJson("/api/v1/users/{$ada}/sessions")->assertOk()->assertJsonCount(2, 'data');

    $this->withToken($key)->deleteJson("/api/v1/users/{$ada}/sessions/{$one->id}")->assertNoContent();
    $this->withToken($key)->getJson("/api/v1/users/{$ada}/sessions")->assertOk()->assertJsonCount(1, 'data');

    // Somebody else's session, named under this user, is not found.
    $bob = uoaUser('bob@acme.test');
    $theirs = $sessions->start($bob, null, ['pwd']);
    $this->withToken($key)->deleteJson("/api/v1/users/{$ada}/sessions/{$theirs->id}")->assertNotFound();

    $this->withToken($key)->deleteJson("/api/v1/users/{$ada}/sessions")->assertNoContent();
    $this->withToken($key)->getJson("/api/v1/users/{$ada}/sessions")->assertOk()->assertJsonCount(0, 'data');
    expect($sessions->active($theirs->id))->not->toBeNull();
})->group('security');

it('sets a password without ever answering with it, and mails it unless told not to', function (): void {
    ['environment' => $environment] = uoaTenant();
    [$key, $keyId] = uoaKey($environment, ['users:write']);
    $ada = uoaUser('ada@acme.test');

    $response = $this->withToken($key)->postJson("/api/v1/users/{$ada}/password", [
        'password' => 'a-long-enough-passphrase',
        'reason' => 'Locked out',
        'revoke' => 'sessions_only',
    ])->assertOk()->assertJsonPath('data.id', $ada);

    expect((string) $response->getContent())->not->toContain('a-long-enough-passphrase')
        ->and(uoaAudit('user.password_set_by_admin')?->actor_id)->toBe($keyId)
        ->and(uoaAudit('user.password_set_by_admin')?->context['reason'] ?? null)->toBe('Locked out');

    Mail::assertSent(AdminAssignedPasswordMail::class, 1);

    $this->withToken($key)->postJson("/api/v1/users/{$ada}/password", ['password' => 'x', 'reason' => 'Too short'])
        ->assertUnprocessable();
});
