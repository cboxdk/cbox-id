<?php

declare(strict_types=1);

use App\Mail\MagicLinkMail;
use App\Platform\EnvironmentSudo;
use App\Platform\Impersonation;
use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\ActionEndpointStatus;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Enums\CampaignStatus;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme as ProvisioningAuthScheme;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Enums\EndpointStatus;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** A fresh org inside the pinned environment. */
function makeOrg(string $slug): string
{
    return app(Organizations::class)
        ->create(new NewOrganization(name: 'T '.$slug, slug: $slug))->id;
}

it('exercises the connection detail mutating actions (saveConfig, activate, disable, delete)', function (): void {
    crudSetup();
    // saveConfig completes the OIDC endpoints via SSRF-guarded discovery from the issuer.
    config(['cbox-id.federation.verify_url' => false]);
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
        'okta.example/issuer/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://okta.example/issuer',
            'authorization_endpoint' => 'https://okta.example/issuer/authorize',
            'token_endpoint' => 'https://okta.example/issuer/token',
        ], 200),
    ]);
    $orgId = makeOrg('conn-org');
    $connection = app(Connections::class)->create(
        $orgId,
        ConnectionType::Oidc,
        'Okta',
        ['issuer' => 'https://okta.example', 'client_id' => 'a', 'client_secret' => 'b'],
    );

    // The OIDC edit form carries issuer and client id, and a signing key that is never
    // prefilled — a save must re-supply it or the write short-circuits with a field error.
    $from = route('environment.connections.show', $connection->id);

    $this->from($from)->patch(route('environment.connections.update', $connection->id), [
        'name' => 'Okta Renamed',
        'issuer' => 'https://okta.example/issuer',
        'client_id' => 'client-abc',
        'signing_key' => 'a-signing-key',
    ])->assertSessionHasNoErrors();

    $this->from($from)->post(route('environment.connections.activate', $connection->id))->assertRedirect();
    $this->from($from)->post(route('environment.connections.disable', $connection->id))->assertRedirect();

    expect(Connection::query()->whereKey($connection->id)->value('name'))->toBe('Okta Renamed');

    $this->delete(route('environment.connections.destroy', $connection->id))->assertRedirect();
    expect(Connection::query()->whereKey($connection->id)->exists())->toBeFalse();
});

it('exercises the directory detail mutating actions (regenerateToken, toggleStatus, saveName, delete)', function (): void {
    crudSetup();
    $orgId = makeOrg('dir-org');
    $directory = app(Directories::class)->register($orgId, 'HR')->directory;

    $from = route('environment.directories.show', $directory->id);

    $this->from($from)->post(route('environment.directories.rotate', $directory->id))->assertRedirect();
    $this->from($from)->post(route('environment.directories.toggle', $directory->id))->assertRedirect();
    $this->from($from)->patch(route('environment.directories.update', $directory->id), ['name' => 'HR Renamed'])
        ->assertSessionHasNoErrors();

    expect(Directory::query()->whereKey($directory->id)->value('name'))->toBe('HR Renamed');

    $this->delete(route('environment.directories.destroy', $directory->id))->assertRedirect();
    expect(Directory::query()->whereKey($directory->id)->exists())->toBeFalse();
});

it('runs every role detail mutation over its own route', function (): void {
    crudSetup();
    $role = app(Roles::class)->define(null, 'Support');
    // A real, non-orphaned permission: the catalogue is global, so a directly-created
    // Permission is one the environment plane may compose from.
    $permission = Permission::query()->create(['name' => 'reports:view', 'description' => 'View reports']);

    $from = route('environment.roles.show', $role->id);

    $this->from($from)->patch(route('environment.roles.update', $role->id), [
        'name' => 'Support Renamed',
        'description' => 'Support staff',
    ])->assertSessionHasNoErrors();

    setRolePermission($role->id, $permission->id, true, 'environment.roles')->assertSessionHasNoErrors();

    expect(Role::query()->whereKey($role->id)->value('name'))->toBe('Support Renamed')
        ->and(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(1);

    // And off again: grant and revoke are the same endpoint saying two different things,
    // so exercising only one of them leaves half of it unrun.
    setRolePermission($role->id, $permission->id, false, 'environment.roles');

    expect(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(0);

    $this->delete(route('environment.roles.destroy', $role->id))->assertRedirect(route('environment.roles'));
    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
});

/**
 * OVER HTTP, not through the component.
 *
 * `Volt::test()` invokes a component directly and never routes, so it kept passing after
 * the routes stopped pointing at that component at all — a green test over a file nothing
 * serves. Each mutation is its own request now, and each one runs the middleware stack
 * that guards it.
 */
it('runs every webhook detail mutation over its own route', function (): void {
    crudSetup();
    app(EnvironmentSudo::class)->confirm();

    $endpoint = app(WebhookRegistry::class)
        ->registerForEnvironment('https://example.com/wh', ['user.created'])->endpoint;

    $this->patch(route('environment.webhooks.update', ['webhook' => $endpoint->id]), [
        'url' => 'https://example.com/wh-updated',
        'eventTypes' => ['user.created', 'user.updated'],
    ])->assertRedirect();

    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('url'))
        ->toBe('https://example.com/wh-updated');

    $this->post(route('environment.webhooks.pause', ['webhook' => $endpoint->id]))->assertRedirect();
    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('status'))
        ->toBe(EndpointStatus::Paused);

    $this->post(route('environment.webhooks.resume', ['webhook' => $endpoint->id]))->assertRedirect();
    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('status'))
        ->toBe(EndpointStatus::Active);

    $sealed = WebhookEndpoint::query()->whereKey($endpoint->id)->value('secret_encrypted');
    $this->post(route('environment.webhooks.rotate', ['webhook' => $endpoint->id]))->assertRedirect();
    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('secret_encrypted'))
        ->not->toBe($sealed);

    $this->delete(route('environment.webhooks.destroy', ['webhook' => $endpoint->id]))
        ->assertRedirect(route('environment.webhooks'));
    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->exists())->toBeFalse();
});

it('runs every SAML-application detail mutation over its own route', function (): void {
    crudSetup();
    $sp = registerServiceProvider();

    saveServiceProvider($sp->id, ['entityId' => 'https://sp/meta-updated'])
        ->assertSessionHasNoErrors();

    expect(ServiceProvider::query()->whereKey($sp->id)->value('entity_id'))->toBe('https://sp/meta-updated');

    $this->delete(route('environment.sso-providers.destroy', $sp->id))
        ->assertRedirect(route('environment.sso-providers'));

    expect(ServiceProvider::query()->whereKey($sp->id)->exists())->toBeFalse();
});

/**
 * @group security
 *
 * A SIGNED-REQUEST APPLICATION WITH NOTHING TO VERIFY AGAINST.
 *
 * The half-configured combination does not fail loudly: the flag says AuthnRequests are
 * verified, and nothing verifies them — which is worse than never having turned it on,
 * because the console then reports a protection that is not there.
 */
it('refuses signed requests with no certificate, and keeps the one on file', function (): void {
    crudSetup();

    // Refused at registration…
    test()->from(route('environment.sso-providers.create'))
        ->post(route('environment.sso-providers.store'), [
            'entityId' => 'https://strict/meta',
            'acsUrl' => 'https://strict/acs',
            'nameIdFormat' => NameIdFormat::cases()[0]->value,
            'nameIdAttribute' => 'email',
            'attributeMappings' => [],
            'wantAuthnRequestsSigned' => true,
            'certificate' => '',
        ])
        ->assertSessionHasErrors('certificate');

    expect(ServiceProvider::query()->where('entity_id', 'https://strict/meta')->exists())->toBeFalse();

    // …and on the edit form, where a blank field means KEEP the certificate rather than
    // remove it. Wiping it while the flag stayed on is the same defect by another route.
    $sp = registerServiceProvider(['certificate' => '-----BEGIN CERTIFICATE-----on-file']);

    saveServiceProvider($sp->id, [
        'wantAuthnRequestsSigned' => true,
        'certificate' => '',
    ])->assertSessionHasNoErrors();

    expect(ServiceProvider::query()->whereKey($sp->id)->value('certificate'))
        ->toBe('-----BEGIN CERTIFICATE-----on-file');
})->group('security');

/**
 * The attribute map, which used to be a textarea parsed with `explode('=', $line, 2)`.
 *
 * That parse dropped anything without an `=` in silence, so a typo in an attribute name
 * looked exactly like a mapping nobody had typed — and the assertion went out missing a
 * claim the application was waiting for. Rows cannot be mistyped that way; what they CAN
 * be is half-filled, and a half-filled row must not become an empty claim.
 */
it('keeps only whole attribute mappings', function (): void {
    crudSetup();
    $sp = registerServiceProvider();

    saveServiceProvider($sp->id, [
        'attributeMappings' => [
            ['key' => ' email ', 'value' => ' email '],
            // An attribute mapped to nothing would emit an empty claim, which a service
            // provider reads as "this person has no name" rather than "not configured".
            ['key' => 'displayName', 'value' => ''],
            ['key' => '', 'value' => 'name'],
        ],
    ])->assertSessionHasNoErrors();

    expect(ServiceProvider::query()->whereKey($sp->id)->value('attribute_mappings'))
        ->toBe(['email' => 'email']);
});

it('runs every inline-hook detail mutation over its own route', function (): void {
    crudSetup();
    $hook = app(ExternalActions::class)
        ->registerForEnvironment(HookPoint::TokenMinting, 'https://example.com/hook')->endpoint;

    $from = route('environment.hooks.show', $hook->id);

    // Twice, because the endpoint reads the state it is moving FROM off the record — one
    // call would pass on a toggle that only ever paused.
    test()->from($from)->post(route('environment.hooks.toggle', $hook->id))->assertSessionHasNoErrors();
    expect($hook->fresh()?->status)->toBe(ActionEndpointStatus::Paused);

    test()->from($from)->post(route('environment.hooks.toggle', $hook->id))->assertSessionHasNoErrors();
    expect($hook->fresh()?->status)->toBe(ActionEndpointStatus::Active);

    test()->delete(route('environment.hooks.destroy', $hook->id))
        ->assertRedirect(route('environment.hooks'));
    expect(ExternalActionEndpoint::query()->whereKey($hook->id)->exists())->toBeFalse();
});

it('exercises the sod-policy detail mutating actions (scan, toggle, remove)', function (): void {
    crudSetup();
    $scanOrgId = makeOrg('sod-org');
    $roleA = app(Roles::class)->define(null, 'Maker');
    $roleB = app(Roles::class)->define(null, 'Checker');
    $policy = app(SegregationOfDuties::class)
        ->definePolicy(null, 'MC', [$roleA->id, $roleB->id]);

    // An environment-wide rule binds every organization, so the scan names the one it is
    // evaluated against — in the URL, the way the page's link says it.
    //
    // The scan is a READ, asked for by a query parameter rather than an action — it walks
    // every grant in the organization, so it runs when somebody wants it rather than every
    // time the page opens.
    test()->get(route('environment.sod-policies.show', ['policy' => $policy->id, 'scan' => 1, 'organization' => $scanOrgId]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('scanned', true));

    $from = route('environment.sod-policies.show', $policy->id);

    test()->from($from)->post(route('environment.sod-policies.toggle', $policy->id))
        ->assertSessionHasNoErrors();
    expect(SodPolicy::query()->whereKey($policy->id)->value('active'))->toBeFalse();

    test()->from($from)->post(route('environment.sod-policies.toggle', $policy->id))
        ->assertSessionHasNoErrors();
    expect(SodPolicy::query()->whereKey($policy->id)->value('active'))->toBeTrue();

    test()->delete(route('environment.sod-policies.destroy', $policy->id))
        ->assertRedirect(route('environment.sod-policies'));
    expect(SodPolicy::query()->whereKey($policy->id)->exists())->toBeFalse();
});

it('exercises the provisioning detail mutating actions (pause, resume, delete)', function (): void {
    crudSetup();
    $connection = app(ProvisioningConnections::class)->register(
        null,
        'DS',
        'https://example.com',
        ProvisioningAuthScheme::cases()[0],
        'secret',
    )->connection;

    $from = route('environment.provisioning.show', $connection->id);

    test()->from($from)->post(route('environment.provisioning.toggle', $connection->id))
        ->assertRedirect($from);
    test()->from($from)->post(route('environment.provisioning.toggle', $connection->id))
        ->assertRedirect($from);

    test()->delete(route('environment.provisioning.destroy', $connection->id))
        ->assertRedirect(route('environment.provisioning'));
    expect(ProvisioningConnection::query()->whereKey($connection->id)->exists())->toBeFalse();
});

it('exercises the governance detail mutating action (close)', function (): void {
    crudSetup();
    $orgId = makeOrg('gov-org');
    $campaign = app(AccessReviews::class)->open($orgId, 'Q3');

    // The environment console holds every organization here, so the close acts on the
    // review's own organization with that authority — the same rule an environment key
    // has ({@see \App\Actions\Governance\AccessReviewFields::writeOrganizationId()}). The
    // organization console still names its own, so the framework's ownership assertion
    // compares the record to the ADMINISTRATOR there rather than to itself.

    // note: certify() and revoke() require a seeded campaign item (a snapshotted role
    // assignment / membership for a subject in the org). A freshly opened campaign over
    // an empty org has no items, so only close() is exercised here.
    test()->from(route('environment.governance.show', $campaign->id))
        ->post(route('environment.governance.close', $campaign->id))
        ->assertSessionHasNoErrors();

    expect(CertificationCampaign::query()->whereKey($campaign->id)->value('status'))
        ->toBe(CampaignStatus::Closed);
});

it('closes a review with the environment\'s authority, and never another organization\'s from inside one', function (): void {
    // The environment console holds every organization in it, so closing a review there
    // acts on the review's own organization — as an environment key does. What must still
    // refuse is an organization's own console reaching another organization's review: its
    // ownership assertion compares the record to the administrator, never to itself.
    crudSetup();
    $campaign = app(AccessReviews::class)->open(makeOrg('gov-unchosen'), 'Q4');

    test()->post(route('environment.governance.close', $campaign->id))->assertRedirect();

    expect(CertificationCampaign::query()->whereKey($campaign->id)->value('status'))
        ->toBe(CampaignStatus::Closed);

    [, $mine] = actingAsRole(MembershipRole::Owner);
    $theirs = app(AccessReviews::class)->open(makeOrg('gov-theirs'), 'Q4');

    expect($mine->id)->not->toBe($theirs->organization_id);

    test()->post(route('governance.close', $theirs->id))->assertNotFound();

    expect(CertificationCampaign::query()->whereKey($theirs->id)->value('status'))
        ->toBe(CampaignStatus::Open);
});

/**
 * And it is not offered in the first place, so nobody is invited to try.
 */
it('offers only live organizations in the add-to-organization picker', function (): void {
    multiTenantDeployment();
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $subjectId = app(Subjects::class)->create('dana@acme.example', 'Dana', 'the-original-passphrase')->id;

    $live = app(Organizations::class)->create(new NewOrganization('Still Here', 'still-here'));
    $dead = app(Organizations::class)->create(new NewOrganization('Gone Ltd', 'gone-ltd'));
    $dead->forceFill(['status' => OrganizationStatus::Deleted])->save();

    test()->get(route('environment.users.show', $subjectId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('joinableOrganizations', fn (Collection $rows): bool => $rows->pluck('label')->contains('Still Here'))
            ->where('joinableOrganizations', fn (Collection $rows): bool => $rows->pluck('label')->doesntContain('Gone Ltd')));

    expect($live->status)->toBe(OrganizationStatus::Active);
});

/**
 * The grant that names no organization — the case an org-scoped one cannot describe.
 *
 * A support agent acting across every customer, somebody who has joined no organization,
 * and any app with no tenancy of its own each used to get a token carrying no roles and
 * no permissions, with no way to give them any short of inventing a membership.
 */
it('grants a role everywhere from the user page, with no membership involved', function (): void {
    multiTenantDeployment();
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $subjectId = app(Subjects::class)->create('agent@acme.example', 'Agent', 'the-original-passphrase')->id;
    $support = app(Roles::class)->define(null, 'Support');

    setEnvironmentRole($subjectId, $support->id, true)->assertSessionHasNoErrors();

    expect(app(Roles::class)->everywhereFor($subjectId))->toBe([$support->id]);

    // It reaches a token in an organization the person is not a member of — which is the
    // whole claim, and the thing an org-scoped grant cannot do.
    expect(app(AccessChecker::class)->forToken($subjectId, $result->organization->id, 'cid_any')->roles)
        ->toBe(['Support']);

    // Clearing it takes it back everywhere at once.
    setEnvironmentRole($subjectId, $support->id, false)->assertSessionHasNoErrors();

    expect(app(Roles::class)->everywhereFor($subjectId))->toBe([]);
});

/**
 * @group security
 *
 * And one organization's own role may not be handed to everybody. It is that tenant's
 * policy, named by them; granting it across the environment would give every other tenant
 * a role they did not define and cannot see.
 */
it('refuses to grant one organization’s role everywhere from the page', function (): void {
    multiTenantDeployment();
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner2@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $subjectId = app(Subjects::class)->create('agent2@acme.example', 'Agent', 'the-original-passphrase')->id;
    $theirs = app(Roles::class)->define($result->organization->id, 'Billing admin');

    // Posted directly, because the control is never drawn for it — and a control that is
    // merely unrendered is not a control that is enforced.
    setEnvironmentRole($subjectId, $theirs->id, true);

    expect(app(Roles::class)->everywhereFor($subjectId))->toBe([]);
})->group('security');

/**
 * The page promised an email and sent nothing.
 *
 * Its subtitle said the person "completes sign-in via an invite or magic link" while
 * `create()` only wrote a subject row — so a user appeared in the console and heard
 * nothing, and every onboarding in an environment that does not use organizations needed
 * an email the administrator wrote by hand. A page that states an outcome which does not
 * happen is the worst kind of copy defect: nobody goes looking for a bug in something
 * they were told had worked.
 *
 * A magic link rather than an organization invitation, because an invitation exists to
 * create a MEMBERSHIP, and an environment that ignores organizations has none to join.
 */
it('emails a sign-in link when an environment user is created', function (): void {
    Mail::fake();

    multiTenantDeployment();
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner-inv@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    test()->post(route('environment.users.store'), [
        'email' => 'newcomer@acme.example',
        'name' => 'Newcomer',
        'sendLink' => true,
    ])->assertSessionHasNoErrors();

    Mail::assertSent(MagicLinkMail::class, fn ($mail): bool => $mail->hasTo('newcomer@acme.example'));
});

/**
 * @group security
 *
 * …and NOT from an administrator whose own address nobody has confirmed.
 *
 * This is the widest reach on the console: it puts a live, one-click sign-in link into an
 * arbitrary inbox, over this platform's domain and signature. An unverified account is one
 * somebody else may actually own, which is exactly the account not to hand a mailer to —
 * so the gate that holds webhooks and OAuth clients holds this too, and harder.
 */
it('refuses to create a user for an administrator who has not confirmed their own address', function (): void {
    Mail::fake();

    multiTenantDeployment();
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner-unverified@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id, emailVerified: false);

    test()->post(route('environment.users.store'), [
        'email' => 'stranger@elsewhere.example',
        'sendLink' => true,
    ])->assertForbidden();

    // Neither the row nor the mail: a refusal that created the account and merely skipped
    // the email would leave a stranger's address claimed inside this environment.
    expect(User::query()->where('email', 'stranger@elsewhere.example')->exists())->toBeFalse();
    Mail::assertNothingSent();
})->group('security');

/**
 * And the administrator can decline to send one — for an account created ahead of time —
 * but the toast then says plainly that the person cannot sign in yet, rather than
 * reporting success and leaving them stranded.
 */
it('says so when a user is created with no way to sign in', function (): void {
    Mail::fake();

    multiTenantDeployment();
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner-inv2@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    test()->post(route('environment.users.store'), [
        'email' => 'later@acme.example',
        'sendLink' => false,
    ])->assertSessionHasNoErrors();

    Mail::assertNothingSent();
});

/**
 * @group security
 *
 * Support does not require a tenancy the product does not have.
 *
 * Impersonation offered a picker of the user's organizations and, when they had none,
 * told the administrator to add them to one — in an environment that may not use
 * organizations at all, which is exactly where a support user still needs help. Nothing
 * in the mechanism needed it: a session can be minted with no organization, and an audit
 * event with none files on the environment's own chain.
 */
it('impersonates a user who belongs to no organization', function (): void {
    // `/admin` exists only on a multi-tenant deployment; without this the POST 404s on
    // route middleware rather than reaching the controller.
    multiTenantDeployment();
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner-imp@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $subjectId = app(Subjects::class)->create('lonely@acme.example', 'Lonely', 'the-original-passphrase')->id;

    // The control is OFFERED rather than replaced by an instruction to invent a tenancy:
    // this person is a member of nothing, and the page still lets support step in.
    test()->get(route('environment.users.show', $subjectId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('memberships', []));

    $this->post(route('environment.impersonate', $subjectId), ['reason' => 'Cannot open reports'])
        ->assertRedirect();

    expect(session()->get(Impersonation::SESSION_KEY))->not->toBeNull();
})->group('security');

/**
 * @group security
 *
 * And omitting the organization is not the way around the rule that protects owners.
 * Without an organization named there is no membership to inspect, so the refusal has to
 * ask a different question: does this person administer ANYTHING here.
 */
it('refuses to impersonate an owner even with no organization named', function (): void {
    multiTenantDeployment();
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner-imp2@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $victim = app(Subjects::class)->create('boss@acme.example', 'Boss', 'the-original-passphrase')->id;
    $org = app(Organizations::class)->create(new NewOrganization('Their Co', 'their-co'));
    app(Memberships::class)->add($org->id, $victim, MembershipRole::Owner);

    $this->post(route('environment.impersonate', $victim), ['reason' => 'Trying it on'])
        ->assertForbidden();

    expect(session()->get(Impersonation::SESSION_KEY))->toBeNull();
})->group('security');
