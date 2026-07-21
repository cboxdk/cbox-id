<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Mail\PasswordResetMail;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlatformAuth;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\ValueObjects\NewServiceProvider;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\Destination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** Provision an account + env, pin the env context + an env-admin session. */
function crudSetup(): array
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

    return ['member' => $r->member, 'envId' => $r->environment->id];
}

it('renders the user + org detail pages and edits a user profile', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('jane@acme.example', 'Jane');
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant A', slug: 'tenant-a'));

    $this->get("/admin/users/{$user->id}")->assertOk()->assertSee('jane@acme.example');
    $this->get("/admin/organizations/{$org->id}")->assertOk()->assertSee('Tenant A');

    Volt::test('environment.users.show', ['user' => $user->id])
        ->set('editName', 'Jane Doe')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect(User::query()->whereKey($user->id)->value('name'))->toBe('Jane Doe');
});

it('deactivates and reactivates a user', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('bob@acme.example', 'Bob');

    Volt::test('environment.users.show', ['user' => $user->id])->call('suspend');
    expect(User::query()->whereKey($user->id)->value('status'))->toBe(UserStatus::Disabled);

    Volt::test('environment.users.show', ['user' => $user->id])->call('reactivate');
    expect(User::query()->whereKey($user->id)->value('status'))->toBe(UserStatus::Active);
});

it('sends a password reset email from the user detail page', function (): void {
    crudSetup();
    Mail::fake();
    $user = app(Subjects::class)->create('reset@acme.example', 'Reset Me');

    Volt::test('environment.users.show', ['user' => $user->id])->call('sendPasswordReset');

    Mail::assertSent(PasswordResetMail::class);
});

it('assigns a user to an organization', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('carol@acme.example', 'Carol');
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant B', slug: 'tenant-b'));

    Volt::test('environment.users.show', ['user' => $user->id])
        ->set('assignOrgId', $org->id)
        ->set('assignRole', 'member')
        ->call('assignOrg')
        ->assertHasNoErrors();

    expect(app(Memberships::class)->of($org->id, $user->id))->not->toBeNull();
});

it('renames and soft-deletes an organization', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Old Name', slug: 'old-name'));

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->set('editName', 'New Name')
        ->set('editSlug', 'new-name')
        ->call('saveDetails')
        ->assertHasNoErrors();
    expect(Organization::query()->whereKey($org->id)->value('name'))->toBe('New Name');

    Volt::test('environment.organizations.show', ['organization' => $org->id])->call('deleteOrg');
    expect(Organization::query()->whereKey($org->id)->value('status'))->toBe(OrganizationStatus::Deleted);
});

it('adds a member to an organization by email', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant C', slug: 'tenant-c'));
    $user = app(Subjects::class)->create('dave@acme.example', 'Dave');

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->set('memberEmail', 'dave@acme.example')
        ->set('memberRole', 'admin')
        ->call('addMember')
        ->assertHasNoErrors();

    expect(app(Memberships::class)->of($org->id, $user->id)?->role)->toBe('admin');
});

it('starts an env-admin impersonation of a member (redirect + marker)', function (): void {
    ['member' => $member] = crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant D', slug: 'tenant-d'));
    $user = app(Subjects::class)->create('erin@acme.example', 'Erin');
    app(Memberships::class)->add($org->id, $user->id, 'member');

    $this->post("/admin/impersonate/{$user->id}", ['organization' => $org->id, 'reason' => 'support ticket 42'])
        ->assertRedirect(route('dashboard'));

    $marker = session('cbox.impersonation');
    expect($marker['subject'])->toBe($user->id)
        ->and($marker['operator'])->toBe($member->id)
        ->and($marker['actor_type'])->toBe('account_member');
});

it('expires an env-admin impersonation back to the env console (not the operator one)', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant I', slug: 'tenant-i'));
    $user = app(Subjects::class)->create('gina@acme.example', 'Gina');
    app(Memberships::class)->add($org->id, $user->id, 'member');

    $this->post("/admin/impersonate/{$user->id}", ['organization' => $org->id, 'reason' => 'support']);

    // Age the marker past the 30-minute window, then hit an authenticated route.
    $marker = session('cbox.impersonation');
    $marker['started_at'] = now()->subMinutes(31)->getTimestamp();
    session()->put('cbox.impersonation', $marker);

    $this->get('/dashboard')->assertRedirect(route('environment.home'));
    expect(session('cbox.impersonation'))->toBeNull();
});

it('refuses to impersonate an owner/admin', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant E', slug: 'tenant-e'));
    $user = app(Subjects::class)->create('frank@acme.example', 'Frank');
    app(Memberships::class)->add($org->id, $user->id, 'admin');

    $this->post("/admin/impersonate/{$user->id}", ['organization' => $org->id, 'reason' => 'nope'])
        ->assertForbidden();
});

it('renders the detail pages for connections, directories, roles, applications and webhooks', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant F', slug: 'tenant-f'));

    $role = app(Roles::class)->define(null, 'Support', 'Support staff');
    $client = app(ClientRegistry::class)->register(
        new NewClient(
            name: 'Test App',
            type: ClientType::Confidential,
            redirectUris: ['https://app.example/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['openid'],
        )
    )->client;
    $webhook = app(WebhookRegistry::class)
        ->register(null, 'https://example.com/in', ['user.created'])->endpoint;
    $directory = app(Directories::class)->register($org->id, 'HR directory')->directory;
    $connection = app(Connections::class)->create(
        $org->id,
        ConnectionType::Oidc,
        'Okta',
        ['issuer' => 'https://okta.example', 'client_id' => 'abc', 'client_secret' => 'shh'],
    );

    $this->get("/admin/roles/{$role->id}")->assertOk()->assertSee('Support');
    $this->get("/admin/applications/{$client->id}")->assertOk()->assertSee('Test App');
    $this->get("/admin/webhooks/{$webhook->id}")->assertOk()->assertSee('example.com');
    $this->get("/admin/directories/{$directory->id}")->assertOk()->assertSee('HR directory');
    $this->get("/admin/single-sign-on/{$connection->id}")->assertOk()->assertSee('Okta');
});

it('sends an organization invitation and lists it as pending', function (): void {
    crudSetup();
    Mail::fake();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant G', slug: 'tenant-g'));

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->set('inviteEmail', 'newbie@acme.example')
        ->set('inviteRole', 'member')
        ->call('invite')
        ->assertHasNoErrors();

    Mail::assertSent(InvitationMail::class);
    expect(app(Invitations::class)->pending($org->id))->toHaveCount(1);
});

it('adds a verified domain to an organization', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant H', slug: 'tenant-h'));

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->set('newDomain', 'acme-h.com')
        ->call('addDomain')
        ->assertHasNoErrors();

    expect(VerifiedDomain::query()->where('organization_id', $org->id)->where('domain', 'acme-h.com')->exists())->toBeTrue();
});

it('resets a user\'s two-factor factors', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('mfa@acme.example', 'Mfa User');
    MfaFactor::query()->create([
        'user_id' => $user->id,
        'type' => 'totp',
        'secret_encrypted' => 'sealed',
        'confirmed_at' => now(),
    ]);

    Volt::test('environment.users.show', ['user' => $user->id])->call('resetMfa');

    expect(MfaFactor::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('requires and stores client_secret for an OIDC connection created via the form', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'OIDC Co', slug: 'oidc-co'));
    $key = "-----BEGIN PUBLIC KEY-----\nMIIB\n-----END PUBLIC KEY-----";

    // The federation OIDC token exchange requires client_secret — the form must too.
    Volt::test('environment.connections.create')
        ->set('type', 'oidc')
        ->set('organization_id', $org->id)
        ->set('name', 'Okta OIDC')
        ->set('issuer', 'https://okta.example')
        ->set('client_id', 'abc')
        ->set('signing_key', $key)
        ->call('create')
        ->assertHasErrors('client_secret');

    // The authorization/token endpoints are completed from the issuer's discovery
    // document (SSRF-guarded) so the connection isn't left half-configured.
    config(['cbox-id.federation.verify_url' => false]);
    Http::fake(['okta.example/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://okta.example',
        'authorization_endpoint' => 'https://okta.example/oauth2/authorize',
        'token_endpoint' => 'https://okta.example/oauth2/token',
        'jwks_uri' => 'https://okta.example/oauth2/keys',
    ], 200)]);

    Volt::test('environment.connections.create')
        ->set('type', 'oidc')
        ->set('organization_id', $org->id)
        ->set('name', 'Okta OIDC')
        ->set('issuer', 'https://okta.example')
        ->set('client_id', 'abc')
        ->set('client_secret', 's3cr3t-value')
        ->set('signing_key', $key)
        ->call('create')
        ->assertHasNoErrors();

    $conn = Connection::query()->where('name', 'Okta OIDC')->firstOrFail();
    $config = app(Connections::class)->config($conn);
    expect($config['client_secret'] ?? null)->toBe('s3cr3t-value')
        ->and($config['authorization_endpoint'] ?? null)->toBe('https://okta.example/oauth2/authorize')
        ->and($config['token_endpoint'] ?? null)->toBe('https://okta.example/oauth2/token');
});

it('refuses an OIDC connection whose issuer has no reachable discovery document', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Bad OIDC', slug: 'bad-oidc'));
    config(['cbox-id.federation.verify_url' => false]);
    Http::fake(['badidp.example/.well-known/openid-configuration' => Http::response('nope', 404)]);

    Volt::test('environment.connections.create')
        ->set('type', 'oidc')
        ->set('organization_id', $org->id)
        ->set('name', 'Bad OIDC')
        ->set('issuer', 'https://badidp.example')
        ->set('client_id', 'abc')
        ->set('client_secret', 's3cr3t')
        ->set('signing_key', "-----BEGIN PUBLIC KEY-----\nMIIB\n-----END PUBLIC KEY-----")
        ->call('create')
        ->assertHasErrors('issuer');

    expect(Connection::query()->where('name', 'Bad OIDC')->exists())->toBeFalse();
});

it('pins an impersonation session to the authorized org, not the subject\'s oldest membership', function (): void {
    crudSetup();
    $ownerOrg = app(Organizations::class)->create(new NewOrganization(name: 'OwnerCo', slug: 'ownerco'));
    $memberOrg = app(Organizations::class)->create(new NewOrganization(name: 'MemberCo', slug: 'memberco'));
    $user = app(Subjects::class)->create('victim@acme.example', 'Victim');
    app(Memberships::class)->add($ownerOrg->id, $user->id, 'owner');   // oldest membership
    app(Memberships::class)->add($memberOrg->id, $user->id, 'member'); // the one we authorize

    $this->post("/admin/impersonate/{$user->id}", ['organization' => $memberOrg->id, 'reason' => 'support'])
        ->assertRedirect(route('dashboard'));

    // Must land in the AUTHORIZED (member) org — never the subject's owner org.
    expect(session(PlatformAuth::ORG_KEY))->toBe($memberOrg->id);
});

it('keeps the last owner when a demotion is attempted (no uncaught 500)', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Solo', slug: 'solo'));
    $user = app(Subjects::class)->create('soleowner@acme.example', 'Sole Owner');
    app(Memberships::class)->add($org->id, $user->id, 'owner');

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->call('changeMemberRole', $user->id, 'member');

    expect(app(Memberships::class)->of($org->id, $user->id)?->role)->toBe('owner');
});

it('rejects an event-hook create with an out-of-environment organization', function (): void {
    crudSetup();
    Volt::test('environment.hooks.create')
        ->set('hook', HookPoint::TokenMinting->value)
        ->set('url', 'https://example.com/hook')
        ->set('organization_id', 'not-a-real-org-id')
        ->call('create')
        ->assertHasErrors('organization_id');
});

it('renders the access-review, stored-token and log-stream detail pages', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Rev Co', slug: 'rev-co'));
    $campaign = app(AccessReviews::class)->open($org->id, 'Q3 Review');
    $secret = app(SecretVault::class)->store('Stripe key', 'stripe', 'sk_test_x');
    $stream = app(LogStreams::class)->create(
        'SIEM export',
        Destination::GenericJson,
        'https://example.com/siem',
        'shh',
        Cbox\LaravelSiem\Enums\AuthScheme::Bearer,
    )->stream;

    $this->get("/admin/access-reviews/{$campaign->id}")->assertOk()->assertSee('Q3 Review');
    $this->get("/admin/stored-tokens/{$secret->id}")->assertOk()->assertSee('Stripe key');
    $this->get("/admin/log-streaming/{$stream->id}")->assertOk()->assertSee('SIEM export');
});

it('rotates and deletes an application', function (): void {
    crudSetup();
    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Rotate App',
        type: ClientType::Confidential,
        redirectUris: ['https://a.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ))->client;
    $before = Client::query()->whereKey($client->id)->value('secret_hash');

    Volt::test('environment.clients.show', ['client' => $client->id])->call('rotateSecret');
    expect(Client::query()->whereKey($client->id)->value('secret_hash'))->not->toBe($before);

    Volt::test('environment.clients.show', ['client' => $client->id])->call('deleteClient');
    expect(Client::query()->whereKey($client->id)->exists())->toBeFalse();
});

it('renders the detail pages for login methods, event hooks, conflict rules and outbound sync', function (): void {
    crudSetup();

    $sp = app(ServiceProviders::class)->register(
        new NewServiceProvider(
            entityId: 'https://sp.example/meta',
            acsUrl: 'https://sp.example/acs',
            nameIdFormat: NameIdFormat::cases()[0],
            nameIdAttribute: 'email',
        )
    );
    $hook = app(ExternalActions::class)
        ->register(HookPoint::TokenMinting, 'https://example.com/hook')->endpoint;
    $roleA = app(Roles::class)->define(null, 'Maker');
    $roleB = app(Roles::class)->define(null, 'Checker');
    $policy = app(SegregationOfDuties::class)
        ->definePolicy(null, 'Maker/Checker', [$roleA->id, $roleB->id]);
    $sync = app(ProvisioningConnections::class)->register(
        null,
        'Downstream',
        'https://example.com',
        AuthScheme::cases()[0],
        'a-secret',
    )->connection;

    $this->get("/admin/login-methods/{$sp->id}")->assertOk()->assertSee('sp.example');
    $this->get("/admin/event-hooks/{$hook->id}")->assertOk()->assertSee('example.com');
    $this->get("/admin/conflict-rules/{$policy->id}")->assertOk()->assertSee('Maker/Checker');
    $this->get("/admin/outbound-sync/{$sync->id}")->assertOk()->assertSee('Downstream');
});

// --- B: org RBAC access-role assignment (distinct from the coarse membership tier) ---

it('adds a member with an RBAC access role from the organization screen', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('dan@acme.example', 'Dan');
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant C', slug: 'tenant-c'));
    // An environment-wide manual role — the kind the Roles console authors.
    $role = app(Roles::class)->define(null, 'Team leads', 'Team leads across the org', null);

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->set('memberEmail', 'dan@acme.example')
        ->set('memberRole', 'member')
        ->set('memberAccessRoles', [$role->id])
        ->call('addMember')
        ->assertHasNoErrors();

    expect(RoleAssignment::query()
        ->where('organization_id', $org->id)->where('user_id', $user->id)->where('role_id', $role->id)
        ->exists())->toBeTrue();
});

it('grants then revokes an access role for a member via the manage toggle', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('erin@acme.example', 'Erin');
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant D', slug: 'tenant-d'));
    app(Memberships::class)->add($org->id, $user->id, 'member');
    $role = app(Roles::class)->define(null, 'Approver', null, null);

    $held = fn (): bool => RoleAssignment::query()
        ->where('organization_id', $org->id)->where('user_id', $user->id)->where('role_id', $role->id)->exists();

    $c = Volt::test('environment.organizations.show', ['organization' => $org->id]);
    $c->call('toggleAccessRole', $user->id, $role->id);
    expect($held())->toBeTrue();
    $c->call('toggleAccessRole', $user->id, $role->id);
    expect($held())->toBeFalse();
});

it('ignores an access-role id that is not assignable in the org (deny-by-default)', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('fred@acme.example', 'Fred');
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant E', slug: 'tenant-e'));
    app(Memberships::class)->add($org->id, $user->id, 'member');

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->call('toggleAccessRole', $user->id, 'role_does_not_exist');

    expect(RoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)->exists())->toBeFalse();
});

it('assigns a user to an org WITH access roles from the user screen', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('gwen@acme.example', 'Gwen');
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant F', slug: 'tenant-f'));
    $role = app(Roles::class)->define(null, 'Manager', null, null);

    Volt::test('environment.users.show', ['user' => $user->id])
        ->set('assignOrgId', $org->id)
        ->set('assignRole', 'member')
        ->set('assignAccessRoles', [$role->id])
        ->call('assignOrg')
        ->assertHasNoErrors();

    expect(RoleAssignment::query()
        ->where('organization_id', $org->id)->where('user_id', $user->id)->where('role_id', $role->id)
        ->exists())->toBeTrue();
});

it('renders a member\'s assigned access role on the organization screen', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('hana@acme.example', 'Hana');
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant G', slug: 'tenant-g'));
    app(Memberships::class)->add($org->id, $user->id, 'member');
    $role = app(Roles::class)->define(null, 'Team leads', null, null);
    app(Roles::class)->assign($org->id, $user->id, $role->id);

    $this->get("/admin/organizations/{$org->id}")
        ->assertOk()
        ->assertSee('Access roles')  // the new RBAC surface label
        ->assertSee('Team leads');   // the assigned role, rendered as a chip
});
