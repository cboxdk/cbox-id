<?php

declare(strict_types=1);

use App\Mail\PasswordResetMail;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
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
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
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
