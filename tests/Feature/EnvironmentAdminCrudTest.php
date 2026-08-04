<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Mail\PasswordResetMail;
use App\Platform\Console\ConsoleScope;
use App\Platform\EnvironmentSudo;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use App\Platform\WorkspaceSudo;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
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
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\ValueObjects\NewServiceProvider;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\Destination;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

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

    expect(app(Memberships::class)->of($org->id, $user->id)?->role?->value)->toBe('admin');
});

it('starts an env-admin impersonation of a member (redirect + marker)', function (): void {
    ['member' => $member] = crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant D', slug: 'tenant-d'));
    $user = app(Subjects::class)->create('erin@acme.example', 'Erin');
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Member);

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
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Member);

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
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Admin);

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

    // Taking away someone's second factor is the most privileged thing this page does,
    // and it used to be the only MFA action in the console that left no trace: the page
    // deleted the rows itself because the contract had no verb for it. Every other MFA
    // mutation was audited. An access review looking for "who removed this user's 2FA"
    // found nothing at all.
    expect(AuditEntry::query()->where('action', 'user.mfa_disabled')->where('target_id', $user->id)->exists())
        ->toBeTrue('resetting a user\'s MFA left no audit entry');
});

it('requires and stores client_secret for an OIDC connection created via the form', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'OIDC Co', slug: 'oidc-co'));
    app(ConsoleScope::class)->chooseOrganization($org->id);
    $key = "-----BEGIN PUBLIC KEY-----\nMIIB\n-----END PUBLIC KEY-----";

    // The federation OIDC token exchange requires client_secret — the form must too.
    Volt::test('console.connections.create')
        ->set('type', 'oidc')
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

    Volt::test('console.connections.create')
        ->set('type', 'oidc')
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
    app(ConsoleScope::class)->chooseOrganization($org->id);
    config(['cbox-id.federation.verify_url' => false]);
    Http::fake(['badidp.example/.well-known/openid-configuration' => Http::response('nope', 404)]);

    Volt::test('console.connections.create')
        ->set('type', 'oidc')
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
    app(Memberships::class)->add($ownerOrg->id, $user->id, MembershipRole::Owner);   // oldest membership
    app(Memberships::class)->add($memberOrg->id, $user->id, MembershipRole::Member); // the one we authorize

    $this->post("/admin/impersonate/{$user->id}", ['organization' => $memberOrg->id, 'reason' => 'support'])
        ->assertRedirect(route('dashboard'));

    // Must land in the AUTHORIZED (member) org — never the subject's owner org.
    expect(session(PlatformAuth::ORG_KEY))->toBe($memberOrg->id);
});

it('keeps the last owner when a demotion is attempted (no uncaught 500)', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Solo', slug: 'solo'));
    $user = app(Subjects::class)->create('soleowner@acme.example', 'Sole Owner');
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Owner);

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->call('changeMemberRole', $user->id, 'member');

    expect(app(Memberships::class)->of($org->id, $user->id)?->role?->value)->toBe('owner');
});

it('rejects an out-of-environment organization for an inline-hook registration', function (): void {
    crudSetup();

    // The create form no longer carries its own organization picker — that field was the
    // second place the answer lived, and the two planes validated it differently. The
    // console chrome owns the choice now, so a crafted id has to go through the scope,
    // which refuses one that is not in this environment.
    expect(fn () => app(ConsoleScope::class)->chooseOrganization('not-a-real-org-id'))
        ->toThrow(AuthorizationException::class);

    // …and with the refused choice never taken there is no organization to register
    // against, so the endpoint is not created at all rather than landing on whichever
    // organization a downstream default would have picked.
    confirmConsoleStepUp();
    Volt::test('console.hooks.create')
        ->set('hook', HookPoint::TokenMinting->value)
        ->set('url', 'https://example.com/hook')
        ->call('register')
        ->assertHasErrors('url');

    expect(ExternalActionEndpoint::query()->exists())->toBeFalse();
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
    $this->get("/admin/log-streaming/{$stream->id}")->assertOk()->assertSee('SIEM export');

    // The vault is the one page here behind a step-up, so this test has to take the step
    // up. It used to assert a bare 200 on this URL, which is a test asserting the ABSENCE
    // of the gate — and that is what kept the weaker half of the pair in place: the
    // organization plane's identical page had demanded a fresh password since it shipped.
    app(EnvironmentSudo::class)->confirm();
    $this->get("/admin/stored-tokens/{$secret->id}")->assertOk()->assertSee('Stripe key');
});

/**
 * The gate itself, stated separately from the render.
 *
 * An environment administrator rotates, re-grants and revokes ANY organization's
 * downstream provider credentials from these three pages. On the organization plane the
 * same actions have always demanded a fresh password or passkey; here they demanded
 * nothing, so an unattended or hijacked env-admin session could plant a grant to an
 * attacker-controlled client id and lease a tenant's production API key.
 */
it('demands a fresh step-up before any token-vault page on the environment plane', function (): void {
    crudSetup();
    $secret = app(SecretVault::class)->store('Stripe key', 'stripe', 'sk_test_x');

    foreach (['/admin/stored-tokens', '/admin/stored-tokens/new', "/admin/stored-tokens/{$secret->id}"] as $url) {
        $this->get($url)->assertRedirect(route('environment.sudo'));
    }

    app(EnvironmentSudo::class)->confirm();

    foreach (['/admin/stored-tokens', '/admin/stored-tokens/new', "/admin/stored-tokens/{$secret->id}"] as $url) {
        $this->get($url)->assertOk();
    }
})->group('security');

/**
 * And the confirmation is this plane's own. The three step-ups keep separate session keys
 * precisely so a confirmation made where less is at stake cannot unlock more: an
 * organization member's sudo must not open the console that administers every
 * organization.
 */
it('never lets an organization-plane step-up satisfy the environment plane', function (): void {
    crudSetup();

    app(Sudo::class)->confirm();
    app(WorkspaceSudo::class)->confirm();

    $this->get('/admin/stored-tokens')->assertRedirect(route('environment.sudo'));
})->group('security');

// RP-initiated logout only returns the browser to the app when the requested
// post_logout_redirect_uri is on THAT client's registered allow-list, byte for byte.
// Until the console could write the list it was always empty, so every sign-out
// stranded the user on Cbox ID's bare "signed out" page.
it('registers and edits an application\'s post-logout redirect URIs', function (): void {
    crudSetup();

    // Environment-wide: crudSetup() picks no organization, and the merged create page
    // takes its organization from the console scope rather than from a field. An app the
    // ENVIRONMENT owns is what this plane always registered — now it says so.
    confirmConsoleStepUp();
    Volt::test('console.clients.create')
        ->set('name', 'Logout App')
        ->set('environmentWide', true)
        ->set('redirectUris', 'https://a.example/cb')
        ->set('postLogoutRedirectUris', "https://a.example/signed-out\nhttps://a.example/bye")
        ->call('create')
        ->assertHasNoErrors();

    $client = Client::query()->where('name', 'Logout App')->firstOrFail();
    expect($client->post_logout_redirect_uris)->toBe([
        'https://a.example/signed-out',
        'https://a.example/bye',
    ]);

    // …and the detail page round-trips the stored list back into the form and saves it.
    Volt::test('console.clients.show', ['client' => $client->id])
        ->assertSet('editPostLogoutRedirectUris', "https://a.example/signed-out\nhttps://a.example/bye")
        ->set('editPostLogoutRedirectUris', 'https://a.example/farewell')
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($client->refresh()->post_logout_redirect_uris)->toBe(['https://a.example/farewell']);
});

it('refuses a cleartext post-logout redirect URI on a public host', function (): void {
    crudSetup();

    confirmConsoleStepUp();
    Volt::test('console.clients.create')
        ->set('name', 'Insecure Logout App')
        ->set('environmentWide', true)
        ->set('redirectUris', 'https://a.example/cb')
        ->set('postLogoutRedirectUris', 'http://a.example/signed-out')
        ->call('create')
        ->assertHasErrors('postLogoutRedirectUris');

    expect(Client::query()->where('name', 'Insecure Logout App')->exists())->toBeFalse();
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

    confirmConsoleStepUp();

    Volt::test('console.clients.show', ['client' => $client->id])->call('rotateSecret');
    expect(Client::query()->whereKey($client->id)->value('secret_hash'))->not->toBe($before);

    // `deleteClient` and the organization plane's `delete` were one capability under two
    // names; the merged component has one.
    Volt::test('console.clients.show', ['client' => $client->id])->call('delete');
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
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Member);
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
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Member);

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
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Member);
    $role = app(Roles::class)->define(null, 'Team leads', null, null);
    app(Roles::class)->assign($org->id, $user->id, $role->id);

    $this->get("/admin/organizations/{$org->id}")
        ->assertOk()
        ->assertSee('Access roles')  // the new RBAC surface label
        ->assertSee('Team leads');   // the assigned role, rendered as a chip
});

it('scopes the org-detail member lookup to the roster, not every user in the environment', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Roster Co', slug: 'roster-co'));
    $memberships = app(Memberships::class);

    // Three members in the org…
    $members = collect(range(1, 3))->map(function (int $i) use ($org, $memberships) {
        $u = app(Subjects::class)->create("member{$i}@roster.example", "Member {$i}");
        $memberships->add($org->id, $u->id, MembershipRole::Member);

        return $u;
    });

    // …and 40 unrelated users in the SAME environment. The old `User::query()->get()`
    // hydrated ALL of these on every render; the fix scopes the name lookup to the roster.
    collect(range(1, 40))->each(fn (int $i) => app(Subjects::class)->create("bystander{$i}@roster.example", "Bystander {$i}"));

    $userQueries = [];
    DB::listen(function ($q) use (&$userQueries): void {
        if (str_contains($q->sql, '"users"') || str_contains($q->sql, '`users`')) {
            $userQueries[] = $q;
        }
    });

    Volt::test('environment.organizations.show', ['organization' => $org->id])
        ->assertOk()
        ->assertSee('Member 1');

    // A users query scoped by `id in (…)` over exactly the roster exists — proving the
    // name lookup is bounded by the members, never an unbounded full-table select of
    // all 43 environment users. (Bindings also carry the environment_id global scope.)
    $memberIds = $members->pluck('id')->map(fn ($x) => (string) $x)->all();
    $scoped = collect($userQueries)->contains(function ($q) use ($memberIds): bool {
        // Strip identifier quoting first — sqlite/PostgreSQL emit `"id" in (`,
        // MySQL `` `id` in ( ``. The capture above already handled both spellings
        // for `users`; this check did not, so it reported an unbounded query on
        // MySQL while the query was correctly scoped.
        if (! str_contains(str_replace(['"', '`'], '', $q->sql), 'id in (')) {
            return false;
        }
        $bindings = array_map('strval', $q->bindings);

        return collect($memberIds)->every(fn (string $id): bool => in_array($id, $bindings, true));
    });

    expect($scoped)->toBeTrue();
});

/**
 * The eyebrow, in rendered HTML rather than in the resolver.
 *
 * The resolver is unit-tested a route at a time, which proves it can answer — not that
 * anything asks. This walks the whole chain: layout → page-header → ConsoleLocation →
 * ConsoleNavigation, on a real request, on the plane where the eyebrow was missing.
 */
it('renders the area name above the page title', function (): void {
    crudSetup();

    // Matched on the eyebrow element itself, not on the word: "People" is also the
    // sidebar's own label for that area, so a bare assertSee passes with the eyebrow
    // deleted — which is exactly what happened the first time this test was written.
    $this->get(route('environment.users'))
        ->assertOk()
        ->assertSee('<p class="cbx-page-eyebrow">People</p>', escape: false);
});
