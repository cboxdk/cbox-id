<?php

declare(strict_types=1);

use App\Models\AdminPortalLink;
use App\Platform\Appearance\Appearance;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\WebhookEventCatalogue;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlatformAuth;
use Cbox\Id\AccessControl\Contracts\ManifestFetcher;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Manifest\DeclaredRole;
use Cbox\Id\AccessControl\Manifest\Manifest;
use Cbox\Id\AccessControl\Models\GroupRoleMapping;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\DirectoryConnectors;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Directory\Models\DirectoryGroup;
use Cbox\Id\Directory\Testing\FakeDirectoryConnector;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\ActionEndpointStatus;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Cbox\Id\Provisioning\Enums\ConnectionStatus;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Enums\EndpointStatus;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\AuthScheme as SiemAuthScheme;
use Cbox\LaravelSiem\Enums\Destination as SiemDestination;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Livewire\Volt\Volt;

/**
 * One component, both planes.
 *
 * The console was built twice and the copies drifted with nothing watching. A merged
 * capability gets a test here: the same page, through either door, offering the same
 * things. The merge is not the guarantee — this is.
 */
function anEnvironmentAdminActingOn(string $slug = 'tenant-parity'): string
{
    // Parity is asserted across two doors, and only ONE of them exists in both deployment
    // shapes. The organization plane is `plane:subject` — it is the console a self-hosted
    // install serves, so its half of every pair below stays on the suite's single-tenant
    // baseline and thereby says the capability survives there. The ENVIRONMENT plane is
    // `/admin`, which 404s unless the deployment is multi-tenant, so this helper — whose
    // whole job is "be an environment administrator" — states that shape.
    //
    // Which answers the question the merge raises: parity is a multi-tenant-only property,
    // because a single-tenant install has one door, not two. What single-tenant keeps is
    // the capability, on the organization plane, and that is what the halves driven through
    // actingAsRole() go on proving.
    multiTenantDeployment();

    platformRootEnvironment();

    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'parity-'.$slug.'@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->owner->id, $provisioned->environment->id);

    $orgId = app(Organizations::class)->create(new NewOrganization('Tenant Co', $slug))->id;
    app(ConsoleScope::class)->chooseOrganization($orgId);

    return $orgId;
}

it('serves access reviews from one controller on the environment plane', function (): void {
    anEnvironmentAdminActingOn();

    $this->get(route('environment.governance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/access-reviews/index')
            ->where('title', 'Access reviews'));

    $this->get(route('environment.governance.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/access-reviews/create'));
})->group('security');

it('serves access reviews from the same controller on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: a campaign URL is something you send to a reviewer.
    actingAsRole(MembershipRole::Owner);

    $this->get(route('governance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/access-reviews/index')
            ->where('title', 'Access reviews'));

    $this->get(route('governance.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/access-reviews/create'));

    // And the routable shape exists on this plane, which is the half that was missing:
    // the organization console had one page and no campaign URL to send anyone.
    expect(Route::has('governance.show'))->toBeTrue()
        ->and(Route::has('governance.create'))->toBeTrue();
})->group('security');

it('takes the organization from the scope rather than a field on the form', function (): void {
    // The create form used to carry its own organization picker on the environment plane
    // and none on the organization plane — the second place the answer lived, validated
    // differently in each.
    $orgId = anEnvironmentAdminActingOn('tenant-scoped');

    openAccessReview([], 'environment.governance')->assertSessionHasNoErrors();

    expect(CertificationCampaign::query()->where('organization_id', $orgId)->exists())->toBeTrue();
})->group('security');

it('refuses to open a review before an organization is chosen', function (): void {
    anEnvironmentAdminActingOn('tenant-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    openAccessReview([], 'environment.governance')->assertSessionHasErrors('name');

    // Refused rather than landed on whichever organization a downstream default picked.
    expect(CertificationCampaign::query()->exists())->toBeFalse();
})->group('security');

/*
|--------------------------------------------------------------------------
| Sync users out (outbound SCIM provisioning)
|--------------------------------------------------------------------------
| The organization plane had one page: an inline register form and pause. The
| environment plane had list → create → detail with pause, resume and delete, plus an
| "All organizations" option on its form. The merge is the union of all of it.
*/

it('serves outbound sync from one controller on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-sync');

    $this->get(route('environment.provisioning'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/outbound-sync/index')
            ->where('title', 'Sync users out'));

    $this->get(route('environment.provisioning.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/outbound-sync/create'));
})->group('security');

it('serves outbound sync from the same controller on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: a connection URL is something you send to whoever runs the app it
    // provisions.
    actingAsRole(MembershipRole::Owner);

    $this->get(route('provisioning'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/outbound-sync/index')
            ->where('title', 'Sync users out'));

    $this->get(route('provisioning.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/outbound-sync/create'));

    expect(Route::has('provisioning.show'))->toBeTrue()
        ->and(Route::has('provisioning.create'))->toBeTrue();
})->group('security');

it('registers an outbound connection against the organization from the scope', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    $orgId = anEnvironmentAdminActingOn('tenant-sync-scoped');

    registerOutboundSync([], 'environment.provisioning')->assertSessionHasNoErrors();

    expect(ProvisioningConnection::query()->where('organization_id', $orgId)->exists())->toBeTrue();
})->group('security');

it('refuses to register an outbound connection before an organization is chosen', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-sync-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    registerOutboundSync([], 'environment.provisioning')->assertSessionHasErrors('name');

    expect(ProvisioningConnection::query()->exists())->toBeFalse();
})->group('security');

it('keeps environment-wide registration on the environment plane', function (): void {
    // What the removed organization picker also carried: its "All organizations" option
    // was never an organization, it was environment-wide coverage — a platform
    // capability, so it survives as its own explicit choice on this plane alone.
    config(['cbox-id.provisioning.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-sync-wide');

    registerOutboundSync([
        'name' => 'Everything',
        'environmentWide' => true,
    ], 'environment.provisioning')->assertSessionHasNoErrors();

    expect(ProvisioningConnection::query()->whereNull('organization_id')->exists())->toBeTrue();
})->group('security');

it('runs the whole outbound lifecycle on the environment plane', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    $orgId = anEnvironmentAdminActingOn('tenant-sync-lifecycle');

    $connection = app(ProvisioningConnections::class)->register(
        $orgId,
        'Downstream',
        'https://scim.example.test/v2',
        AuthScheme::Bearer,
        'tok_123',
    )->connection;

    $from = route('environment.provisioning.show', $connection->id);

    test()->from($from)->post(route('environment.provisioning.toggle', $connection->id))
        ->assertRedirect($from);
    expect($connection->fresh()->status)->toBe(ConnectionStatus::Paused);

    test()->from($from)->post(route('environment.provisioning.toggle', $connection->id))
        ->assertRedirect($from);
    expect($connection->fresh()->status)->toBe(ConnectionStatus::Active);

    test()->delete(route('environment.provisioning.destroy', $connection->id))
        ->assertRedirect(route('environment.provisioning'));
    expect(ProvisioningConnection::query()->whereKey($connection->id)->exists())->toBeFalse();
})->group('security');

/*
|--------------------------------------------------------------------------
| Inline hooks (external actions)
|--------------------------------------------------------------------------
| The organization plane had one page: an inline register form, row-level pause,
| activate and remove, and a Dismiss for the reveal-once signing secret — but exactly
| ONE of the six hook points. The environment plane had list → create → detail with
| pause, activate and remove, all six hook points and an "All organizations" option on
| its form, and no way to clear the secret from the screen. The merge is the union of
| all of it.
*/

it('serves inline hooks from one controller on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-hooks');

    $this->get(route('environment.hooks'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/hooks/index')
            ->where('title', 'Inline hooks'));

    confirmConsoleStepUp();
    $this->get(route('environment.hooks.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/hooks/create'));
})->group('security');

it('serves inline hooks from the same controller on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: the reveal-once signing secret needs somewhere to land that is not the
    // row you just submitted.
    actingAsRole(MembershipRole::Owner);

    $this->get(route('hooks'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/hooks/index')
            ->where('title', 'Inline hooks'));

    confirmConsoleStepUp();
    $this->get(route('hooks.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/hooks/create'));

    expect(Route::has('hooks.show'))->toBeTrue()
        ->and(Route::has('hooks.create'))->toBeTrue();
})->group('security');

it('offers every hook point on both planes', function (): void {
    // The drift that mattered most here. The organization console hard-coded a single
    // <option>, so a tenant could enrich a token and could NOT refuse a sign-in, a
    // sign-up or a password change — four of the six points the enum publishes, missing
    // from one console and present in the other, with nothing saying so.
    $expected = array_map(fn (HookPoint $point): string => $point->label(), HookPoint::cases());

    $offered = fn (AssertableInertia $page): bool => $page->toArray()['props']['points'] !== null;

    anEnvironmentAdminActingOn('tenant-hookpoints');
    confirmConsoleStepUp();
    $this->get(route('environment.hooks.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'points',
            fn (Collection $points): bool => $points->pluck('label')->all() === $expected,
        ));

    actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();
    $this->get(route('hooks.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'points',
            fn (Collection $points): bool => $points->pluck('label')->all() === $expected,
        ));
})->group('security');

it('registers a hook against the organization from the scope', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = anEnvironmentAdminActingOn('tenant-hooks-scoped');

    confirmConsoleStepUp();
    registerHook([], 'environment.hooks')->assertSessionHasNoErrors();

    expect(ExternalActionEndpoint::query()->where('organization_id', $orgId)->exists())->toBeTrue();
})->group('security');

it('refuses to register a hook before an organization is chosen', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-hooks-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    confirmConsoleStepUp();
    registerHook([], 'environment.hooks')->assertSessionHasErrors('url');

    expect(ExternalActionEndpoint::query()->exists())->toBeFalse();
})->group('security');

it('keeps environment-wide hook registration on the environment plane', function (): void {
    // What the removed organization picker also carried: its "All organizations" option
    // was never an organization, it was an endpoint the environment itself owns.
    config(['cbox-id.external_actions.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-hooks-wide');

    confirmConsoleStepUp();
    registerHook([
        'url' => 'https://hooks.example.test/everyone',
        'environmentWide' => true,
    ], 'environment.hooks')->assertSessionHasNoErrors();

    expect(ExternalActionEndpoint::query()->whereNull('organization_id')->exists())->toBeTrue();
})->group('security');

it('refuses environment-wide registration from the organization plane', function (): void {
    // At most of these points a hook can REFUSE the operation, so an environment-wide
    // endpoint minted by a tenant could stop every other tenant signing in. The checkbox
    // is not rendered for them; the property is still client-settable, so the refusal is
    // in the action.
    config(['cbox-id.external_actions.verify_url' => false]);
    actingAsRole(MembershipRole::Owner);

    confirmConsoleStepUp();
    registerHook([
        'url' => 'https://hooks.example.test/everyone',
        'environmentWide' => true,
    ])->assertForbidden();

    expect(ExternalActionEndpoint::query()->exists())->toBeFalse();
})->group('security');

it('runs the whole hook lifecycle on the environment plane', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = anEnvironmentAdminActingOn('tenant-hooks-lifecycle');

    $endpoint = app(ExternalActions::class)
        ->register(HookPoint::TokenMinting, 'https://hooks.example.test/token', $orgId)->endpoint;

    $from = route('environment.hooks.show', $endpoint->id);

    test()->from($from)->post(route('environment.hooks.toggle', $endpoint->id))->assertSessionHasNoErrors();
    expect($endpoint->fresh()?->status)->toBe(ActionEndpointStatus::Paused);

    test()->from($from)->post(route('environment.hooks.toggle', $endpoint->id))->assertSessionHasNoErrors();
    expect($endpoint->fresh()?->status)->toBe(ActionEndpointStatus::Active);

    test()->delete(route('environment.hooks.destroy', $endpoint->id))
        ->assertRedirect(route('environment.hooks'));
    expect(ExternalActionEndpoint::query()->whereKey($endpoint->id)->exists())->toBeFalse();
})->group('security');

it('reveals the signing secret on the environment plane too', function (): void {
    // Only the organization console showed this at all. Dropping it in the merge would
    // have meant registering an endpoint on the plane whose administrator holds every
    // organization here, and never being given the secret it minted.
    config(['cbox-id.external_actions.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-hooks-secret');

    confirmConsoleStepUp();
    registerHook([], 'environment.hooks')->assertSessionHasNoErrors();

    $endpoint = ExternalActionEndpoint::query()->firstOrFail();
    $revealed = session()->get(SessionKey::FLASH_DATA)['newSecret'] ?? '';

    expect($revealed)->toMatch('/[0-9a-f]{64}/');

    // ONCE. Dismissing it is the browser's own affordance — by then the secret is already
    // off the server — and is held in tests/Browser/HooksTest.php.
    test()->get(route('environment.hooks.show', $endpoint->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('newSecret', $revealed));

    test()->get(route('environment.hooks.show', $endpoint->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('newSecret'));
})->group('security');

/*
|--------------------------------------------------------------------------
| Role conflicts (segregation of duties)
|--------------------------------------------------------------------------
| The organization plane had one page: an inline define form, an activate toggle, and
| a standing violations report. The environment plane had list → create → detail with
| define, toggle, evaluate and delete, an "All organizations" option on its form, and
| no violations report anywhere. The merge is the union of all of it.
*/

it('serves role conflicts from one controller on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-sod');

    $this->get(route('environment.sod-policies'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/role-conflicts/index')
            ->where('title', 'Role conflicts'));

    $this->get(route('environment.sod-policies.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/role-conflicts/create'));
})->group('security');

it('serves role conflicts from the same controller on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: a rule URL is something you send to whoever owns the control.
    actingAsRole(MembershipRole::Owner);

    $this->get(route('sod-policies'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/role-conflicts/index')
            ->where('title', 'Role conflicts'));

    $this->get(route('sod-policies.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/role-conflicts/create'));

    expect(Route::has('sod-policies.show'))->toBeTrue()
        ->and(Route::has('sod-policies.create'))->toBeTrue();
})->group('security');

it('defines a rule against the organization from the scope', function (): void {
    $orgId = anEnvironmentAdminActingOn('tenant-sod-scoped');
    $a = app(Roles::class)->define($orgId, 'create-po');
    $b = app(Roles::class)->define($orgId, 'approve-pay');

    defineRoleConflict(['roles' => [$a->id, $b->id]], 'environment.sod-policies')
        ->assertSessionHasNoErrors();

    expect(SodPolicy::query()->where('organization_id', $orgId)->exists())->toBeTrue();
})->group('security');

it('refuses to define a rule before an organization is chosen', function (): void {
    $orgId = anEnvironmentAdminActingOn('tenant-sod-unchosen');
    $a = app(Roles::class)->define($orgId, 'create-po');
    $b = app(Roles::class)->define($orgId, 'approve-pay');
    session()->forget(ConsoleScope::SELECTION_KEY);

    defineRoleConflict(['roles' => [$a->id, $b->id]], 'environment.sod-policies')
        ->assertSessionHasErrors('name');

    expect(SodPolicy::query()->exists())->toBeFalse();
})->group('security');

it('keeps environment-wide rules on the environment plane', function (): void {
    // What the removed organization picker also carried: its "All organizations" option
    // was never an organization, it was a rule that binds every organization here — so
    // it survives as its own explicit choice on this plane alone.
    $orgId = anEnvironmentAdminActingOn('tenant-sod-wide');
    $a = app(Roles::class)->define($orgId, 'create-po');
    $b = app(Roles::class)->define($orgId, 'approve-pay');

    defineRoleConflict([
        'name' => 'Everywhere',
        'roles' => [$a->id, $b->id],
        'environmentWide' => true,
    ], 'environment.sod-policies')->assertSessionHasNoErrors();

    expect(SodPolicy::query()->whereNull('organization_id')->exists())->toBeTrue();
})->group('security');

it('refuses an organization admin a rule that binds every organization', function (): void {
    // Forged, not clicked: the checkbox is not rendered on this plane, so the refusal has
    // to live in the controller — an organization admin writing an environment-wide rule
    // would be legislating for organizations that are not theirs.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $a = app(Roles::class)->define($org->id, 'create-po');
    $b = app(Roles::class)->define($org->id, 'approve-pay');

    defineRoleConflict([
        'name' => 'Everywhere',
        'roles' => [$a->id, $b->id],
        'environmentWide' => true,
    ])->assertSessionHasErrors('environmentWide');

    expect(SodPolicy::query()->exists())->toBeFalse();
})->group('security');

it('gives the organization plane the evaluate and delete it never had', function (): void {
    // Both existed only on the environment plane. An organization's own rule is its own to
    // evaluate and to remove — the environment-wide one still is not.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $a = app(Roles::class)->define($org->id, 'create-po');
    $b = app(Roles::class)->define($org->id, 'approve-pay');
    $policy = app(SegregationOfDuties::class)->definePolicy($org->id, 'PO vs pay', [$a->id, $b->id]);

    $this->get(route('sod-policies.show', ['policy' => $policy->id, 'scan' => 1]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scannable', true)
            ->where('scanned', true));

    $this->delete(route('sod-policies.destroy', $policy->id))
        ->assertRedirect(route('sod-policies'));

    expect(SodPolicy::query()->whereKey($policy->id)->exists())->toBeFalse();
})->group('security');

it('shows an organization the environment-wide rule it cannot change', function (): void {
    // The organization must SEE what constrains it. The detail page is new to this plane,
    // so it inherits the read-only treatment the list already had rather than offering an
    // organization admin a delete button for the control plane's own rule.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $a = app(Roles::class)->define($org->id, 'create-po');
    $b = app(Roles::class)->define($org->id, 'approve-pay');
    $policy = app(SegregationOfDuties::class)->definePolicy(null, 'Env-wide PO vs pay', [$a->id, $b->id]);

    $this->get(route('sod-policies.show', $policy->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rule.name', 'Env-wide PO vs pay')
            // No owner and no switch: it binds every organization here, and this one is
            // not entitled to turn it off — which would let them grant themselves the very
            // pair it forbids.
            ->where('rule.owner', null)
            ->where('mayChange', false));

    // And forged, not merely unrendered — the write set is a query, so the id matches
    // nothing in it.
    $this->delete(route('sod-policies.destroy', $policy->id))->assertNotFound();
    $this->post(route('sod-policies.toggle', $policy->id))->assertNotFound();

    expect(SodPolicy::query()->whereKey($policy->id)->exists())->toBeTrue()
        ->and(SodPolicy::query()->whereKey($policy->id)->value('active'))->toBeTrue();
})->group('security');

it('refuses an organization admin with no organization at all', function (): void {
    // The nullable reader answers null both for "an environment administrator has not
    // chosen an organization yet" and for "this member has none", and the first means
    // "show the whole environment". Told apart here, because conflating them turns an
    // organization page into a list of every rule in the environment.
    $subject = app(Subjects::class)->create('orphan@acme.test', 'Orphan', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-orphan'));
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, null, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $this->get(route('sod-policies'))->assertForbidden();
})->group('security');

it('refuses an organization admin another organization\'s rule', function (): void {
    // The environment plane resolved a rule anywhere in the environment, which is right
    // for an administrator who holds it. Serving the same controller to an organization
    // admin would have handed them every other organization's rules by id.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-sod'));
    $role = app(Roles::class)->define($other->id, 'create-po');
    $second = app(Roles::class)->define($other->id, 'approve-pay');
    $policy = app(SegregationOfDuties::class)->definePolicy($other->id, 'Not yours', [$role->id, $second->id]);

    $this->get(route('sod-policies.show', $policy->id))->assertNotFound();

    $this->get(route('sod-policies'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'rules',
            fn (Collection $rules): bool => $rules->pluck('name')->doesntContain('Not yours'),
        ));

    $this->post(route('sod-policies.toggle', $policy->id))->assertNotFound();
    $this->delete(route('sod-policies.destroy', $policy->id))->assertNotFound();
})->group('security');

/*
|--------------------------------------------------------------------------
| Activity log
|--------------------------------------------------------------------------
| The organization plane filtered rows to the reader's own organization and offered an
| action filter. The environment plane filtered rows to nothing at all — the whole
| environment — and offered a broader search. Both controls survive; the row scoping is
| the half that had to be decided rather than merged, because the unscoped query served
| to an organization admin is every other tenant's audit trail.
*/

/** Append an entry to one organization's trail (or the environment's, with null). */
function anAuditEntry(string $action, ?string $organizationId = null): void
{
    app(AuditLog::class)->record(new AuditEvent(
        action: $action,
        actorType: ActorType::System,
        organizationId: $organizationId,
    ));
}

/**
 * The actions the page actually resolved, read from the view data rather than the
 * markup: a filter term is echoed back into its own input, so an HTML assertion matches
 * the query string rather than a leaked row.
 *
 * @return list<string>
 */
function auditActions(string $route, array $query = []): array
{
    $props = test()->get(route($route, $query))->assertOk()->inertiaProps('entries');

    return collect(is_array($props) ? $props : [])->pluck('action')->all();
}

it('serves the activity log from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-audit');

    $this->get(route('environment.audit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/audit'));
})->group('security');

it('serves the activity log from the same component on the organization plane', function (): void {
    actingAsRole(MembershipRole::Owner);

    $this->get(route('audit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/audit'));
})->group('security');

it('scopes the trail to the organization the environment console is acting on', function (): void {
    // The picker is not decoration: an environment administrator reading one tenant's
    // trail must be reading THAT tenant's, not a merged feed of every tenant's.
    $orgId = anEnvironmentAdminActingOn('tenant-audit-scoped');
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-audit'))->id;

    anAuditEntry('mine.recorded', $orgId);
    anAuditEntry('theirs.recorded', $other);

    expect(auditActions('environment.audit'))
        ->toContain('mine.recorded')
        ->not->toContain('theirs.recorded');
})->group('security');

it('never shows an organization admin another organization\'s trail', function (): void {
    // THE escalation this merge had to close. The environment component read
    // `AuditEntry::query()` with no organization filter, which was safe only because an
    // environment administrator was its sole caller. On the organization plane the same
    // query is every tenant's security trail, handed to one tenant's admin.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-audit-org'))->id;

    anAuditEntry('mine.recorded', $org->id);
    anAuditEntry('theirs.recorded', $other);
    anAuditEntry('environment.recorded');

    // Not the other organization's, and not the control plane's own either.
    expect(auditActions('audit'))
        ->toContain('mine.recorded')
        ->not->toContain('theirs.recorded')
        ->not->toContain('environment.recorded');

    // And the search box is not an enumeration oracle around the scope.
    expect(auditActions('audit', ['q' => 'theirs.recorded']))->toBe([]);
})->group('security');

it('keeps the whole-environment trail on the environment plane', function (): void {
    // What "no organization chosen" means on this plane, and the capability the removed
    // picker also carried: the environment's own overview, across every tenant in it.
    $orgId = anEnvironmentAdminActingOn('tenant-audit-wide');
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-audit-wide'))->id;
    session()->forget(ConsoleScope::SELECTION_KEY);

    anAuditEntry('mine.recorded', $orgId);
    anAuditEntry('theirs.recorded', $other);
    anAuditEntry('environment.recorded');

    expect(auditActions('environment.audit'))
        ->toContain('mine.recorded')
        ->toContain('theirs.recorded')
        ->toContain('environment.recorded');
})->group('security');

it('refuses the activity log to an organization admin with no organization at all', function (): void {
    // The nullable reader answers null both for "an environment administrator has not
    // chosen one yet" and for "this member has none", and the first means "show the whole
    // environment". Told apart here, because conflating them turns one tenant's audit page
    // into every tenant's.
    $subject = app(Subjects::class)->create('orphan-audit@acme.test', 'Orphan', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-orphan-audit'));
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, null, MembershipRole::Owner);

    session([PlatformAuth::SESSION_KEY => $session->id]);

    anAuditEntry('environment.recorded');

    $this->get(route('audit'))->assertForbidden();
})->group('security');

it('offers both planes both of the filters they used to have one each of', function (): void {
    // The union. The organization plane had an action filter and no search; the
    // environment plane had a search over action-or-target and no action filter.
    $orgId = anEnvironmentAdminActingOn('tenant-audit-filters');

    app(AuditLog::class)->record(new AuditEvent(
        action: 'member.added',
        actorType: ActorType::System,
        organizationId: $orgId,
        targetType: 'directory',
    ));
    anAuditEntry('client.rotated', $orgId);

    // The organization plane's action filter, now on the environment plane.
    expect(auditActions('environment.audit', ['action' => 'client']))
        ->toBe(['client.rotated']);

    // The environment plane's search — which matches the TARGET type too, the half an
    // action filter cannot answer — now on the organization plane.
    [, $org] = actingAsRole(MembershipRole::Owner);
    app(AuditLog::class)->record(new AuditEvent(
        action: 'member.added',
        actorType: ActorType::System,
        organizationId: $org->id,
        targetType: 'directory',
    ));
    anAuditEntry('client.rotated', $org->id);

    expect(auditActions('audit', ['q' => 'directory']))
        ->toBe(['member.added']);
})->group('security');

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
| The organization plane had one page: an inline create form, a row-level pause, a
| Dismiss for the reveal-once signing secret — and seven event types. The environment
| plane had list → create → detail with pause, resume, secret rotation, subscription
| editing, a delivery log and delete, twenty-four event types, and every endpoint it
| created was platform-wide because it had no notion of acting on one tenant. The merge
| is the union of all of it.
|
| So a tenant administrator could create an endpoint and pause it, and could then not
| start it again, re-key it after a leak, change what it listened for, or take it away.
*/

it('serves webhooks from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-webhooks');

    $this->get(route('environment.webhooks'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/webhooks/index'));
    confirmConsoleStepUp();
    $this->get(route('environment.webhooks.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/webhooks/create'));
})->group('security');

it('serves webhooks from the same component on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: an endpoint URL is something you send to whoever runs the receiving
    // system, and the reveal-once signing secret needs somewhere to land that is not the
    // row you just submitted.
    actingAsRole(MembershipRole::Owner);

    $this->get(route('webhooks'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/webhooks/index'));
    confirmConsoleStepUp();
    $this->get(route('webhooks.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/webhooks/create'));

    expect(Route::has('webhooks.show'))->toBeTrue()
        ->and(Route::has('webhooks.create'))->toBeTrue();
})->group('security');

it('offers the same event catalogue on both planes', function (): void {
    // The drift that decided what a subscriber ever finds out about. The organization
    // console listed seven types; the environment console listed twenty-four. A tenant
    // could not subscribe to a password reset, an MFA enrolment, a passkey registration,
    // a role change or an invitation being accepted — in the console whose whole job is
    // telling their systems what happened — and nothing on the page said so.
    anEnvironmentAdminActingOn('tenant-webhook-events');
    confirmConsoleStepUp();
    $environment = $this->get(route('environment.webhooks.create'))->assertOk()->inertiaProps('events');

    actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();
    $organization = $this->get(route('webhooks.create'))->assertOk()->inertiaProps('events');

    // The whole catalogue, and the SAME catalogue — asserted as a set rather than by
    // looking for each string somewhere in a document, which would also pass for a page
    // that listed them and then offered a shorter set to submit.
    expect($environment)->toBe(WebhookEventCatalogue::EVENTS)
        ->and($organization)->toBe(WebhookEventCatalogue::EVENTS);

    // The seven the organization console had are a subset of what it offers now, so the
    // assertion above cannot pass by having quietly shrunk the environment plane's list.
    expect(WebhookEventCatalogue::EVENTS)->toContain('user.password_reset', 'user.mfa_enrolled', 'role.unassigned');
})->group('security');

it('registers an endpoint against the organization from the scope', function (): void {
    config(['cbox-id.webhooks.verify_url' => false]);
    $orgId = anEnvironmentAdminActingOn('tenant-webhooks-scoped');

    confirmConsoleStepUp();
    $this->from(route('environment.webhooks.create'))
        ->post(route('environment.webhooks.store'), [
            'url' => 'https://hooks.example.test/events',
            'eventTypes' => ['user.created'],
        ])
        ->assertSessionHasNoErrors();

    expect(WebhookEndpoint::query()->where('organization_id', $orgId)->exists())->toBeTrue();
})->group('security');

it('refuses to register an endpoint before an organization is chosen', function (): void {
    config(['cbox-id.webhooks.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-webhooks-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    confirmConsoleStepUp();
    $this->from(route('environment.webhooks.create'))
        ->post(route('environment.webhooks.store'), [
            'url' => 'https://hooks.example.test/events',
            'eventTypes' => ['user.created'],
        ])
        ->assertSessionHasErrors('url');

    expect(WebhookEndpoint::query()->exists())->toBeFalse();
})->group('security');

it('keeps environment-wide webhook registration on the environment plane', function (): void {
    // The capability the environment console carried implicitly: it passed a null
    // organization on every create, so every endpoint it made received EVERY
    // organization's events. Taking the organization from the console chrome would have
    // dropped that silently, so it survives as its own explicit choice on this plane.
    config(['cbox-id.webhooks.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-webhooks-wide');

    confirmConsoleStepUp();
    $this->from(route('environment.webhooks.create'))
        ->post(route('environment.webhooks.store'), [
            'url' => 'https://hooks.example.test/everyone',
            'eventTypes' => ['user.created'],
            'environmentWide' => true,
        ])
        ->assertSessionHasNoErrors();

    expect(WebhookEndpoint::query()->whereNull('organization_id')->exists())->toBeTrue();
})->group('security');

it('refuses environment-wide webhook registration from the organization plane', function (): void {
    // A platform-wide endpoint receives every tenant's events in this environment —
    // members joining, sign-ins failing, roles changing — so one minted by a tenant is a
    // subscription to the other tenants. The checkbox is not rendered for them; the field
    // is still POSTable, so the refusal is in the controller.
    config(['cbox-id.webhooks.verify_url' => false]);
    actingAsRole(MembershipRole::Owner);

    confirmConsoleStepUp();
    $this->from(route('webhooks.create'))
        ->post(route('webhooks.store'), [
            'url' => 'https://hooks.example.test/everyone',
            'eventTypes' => ['user.created'],
            'environmentWide' => true,
        ])
        ->assertForbidden();

    expect(WebhookEndpoint::query()->exists())->toBeFalse();
})->group('security');

it('runs the whole webhook lifecycle on the environment plane', function (): void {
    config(['cbox-id.webhooks.verify_url' => false]);
    $orgId = anEnvironmentAdminActingOn('tenant-webhooks-lifecycle');

    $endpoint = app(WebhookRegistry::class)
        ->register($orgId, 'https://hooks.example.test/events', ['user.created'])->endpoint;

    confirmConsoleStepUp();

    $from = route('environment.webhooks.show', $endpoint->id);

    $this->from($from)->patch(route('environment.webhooks.update', $endpoint->id), [
        'url' => 'https://hooks.example.test/events-v2',
        'eventTypes' => ['user.created', 'user.updated'],
    ])->assertSessionHasNoErrors();

    $this->from($from)->post(route('environment.webhooks.pause', $endpoint->id))->assertRedirect();
    $this->from($from)->post(route('environment.webhooks.resume', $endpoint->id))->assertRedirect();
    $this->from($from)->post(route('environment.webhooks.rotate', $endpoint->id))->assertRedirect();

    expect($endpoint->fresh()?->url)->toBe('https://hooks.example.test/events-v2')
        ->and($endpoint->fresh()?->status)->toBe(EndpointStatus::Active);

    $this->from($from)->delete(route('environment.webhooks.destroy', $endpoint->id));
    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->exists())->toBeFalse();
})->group('security');

it('gives the organization plane the resume, rotate, edit and delete it never had', function (): void {
    // The whole point of the merge. Every one of these was environment-plane only, so a
    // tenant administrator who paused an endpoint had no way to start it again, and no
    // way at all to re-key one whose signing secret had leaked.
    config(['cbox-id.webhooks.verify_url' => false]);
    [, $org] = actingAsRole(MembershipRole::Owner);

    $endpoint = app(WebhookRegistry::class)
        ->register($org->id, 'https://hooks.example.test/mine', ['user.created'])->endpoint;
    $sealed = $endpoint->secret_encrypted;

    $from = route('webhooks.show', $endpoint->id);

    $this->from($from)->patch(route('webhooks.update', $endpoint->id), [
        'url' => 'https://hooks.example.test/mine-v2',
        'eventTypes' => ['user.created', 'role.assigned'],
    ])->assertSessionHasNoErrors();

    $this->from($from)->post(route('webhooks.pause', $endpoint->id))->assertRedirect();

    expect($endpoint->fresh()?->url)->toBe('https://hooks.example.test/mine-v2')
        ->and($endpoint->fresh()?->status)->toBe(EndpointStatus::Paused);

    confirmConsoleStepUp();

    $this->from($from)->post(route('webhooks.resume', $endpoint->id))->assertRedirect();
    $this->from($from)->post(route('webhooks.rotate', $endpoint->id))->assertRedirect();

    // The rotation has to have re-keyed the endpoint, not merely returned without error:
    // a rotate that silently did nothing leaves a leaked secret verifying deliveries.
    expect($endpoint->fresh()?->status)->toBe(EndpointStatus::Active)
        ->and($endpoint->fresh()?->secret_encrypted)->not->toBe($sealed);

    $this->from($from)->delete(route('webhooks.destroy', $endpoint->id));
    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->exists())->toBeFalse();
})->group('security');

it('refuses an organization admin another organization\'s endpoint', function (): void {
    // The environment plane resolved an endpoint anywhere in the environment on its
    // primary key alone, which is right for an administrator who holds the environment.
    // Serving the same component on the organization plane with that lookup would hand a
    // tenant administrator every other tenant's endpoint by id — and rotateSecret with
    // it, which mints a live signing secret for somebody else's receiver.
    config(['cbox-id.webhooks.verify_url' => false]);
    [, $org] = actingAsRole(MembershipRole::Owner);

    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-webhooks'));
    $theirs = app(WebhookRegistry::class)
        ->register($other->id, 'https://hooks.example.test/not-yours', ['user.created'])->endpoint;
    $sealed = $theirs->secret_encrypted;

    // The deep link is refused: the page resolves its endpoint through the same scoped
    // lookup every action uses, so there is nothing to reach.
    $this->get(route('webhooks.show', $theirs->id))->assertNotFound();

    $this->get(route('webhooks'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('endpoints', fn (Collection $endpoints): bool => $endpoints
                ->pluck('url')
                ->every(fn (string $url): bool => ! str_contains($url, 'not-yours'))));

    // AND EVERY WRITE, not merely the page. Under Volt the forged id had to be smuggled
    // into a snapshot; here it is simply the URL, which is the plainer version of the
    // same attack — and each of these is its own request with its own resolution, so
    // each one has to refuse on its own.
    foreach (['pause', 'resume', 'rotate'] as $action) {
        $this->post(route('webhooks.'.$action, $theirs->id))->assertNotFound();
    }

    $this->patch(route('webhooks.update', $theirs->id), [
        'url' => 'https://hooks.example.test/hijacked',
        'eventTypes' => ['user.created'],
    ])->assertNotFound();

    $this->delete(route('webhooks.destroy', $theirs->id))->assertNotFound();

    // The refusal has to be the endpoint's survival, not just an unhappy response.
    expect($theirs->fresh()?->secret_encrypted)->toBe($sealed)
        ->and($theirs->fresh()?->url)->toBe('https://hooks.example.test/not-yours')
        ->and($theirs->fresh()?->status)->toBe(EndpointStatus::Active);
})->group('security');

it('shows an organization the environment-wide endpoint it cannot change', function (): void {
    // The organization must SEE what receives its events. The detail page is new to this
    // plane, so it renders the environment's own endpoint read-only rather than offering
    // a tenant administrator a Rotate button for the control plane's credential — and
    // withholds the delivery log, which for a platform-wide endpoint is a record of what
    // happened in every OTHER organization here.
    config(['cbox-id.webhooks.verify_url' => false]);
    actingAsRole(MembershipRole::Owner);

    $endpoint = app(WebhookRegistry::class)
        ->registerForEnvironment('https://hooks.example.test/everyone', ['user.created'])->endpoint;
    $sealed = $endpoint->secret_encrypted;

    $this->get(route('webhooks.show', $endpoint->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('endpoint.url', 'https://hooks.example.test/everyone')
            // The controls are not offered rather than offered and refused…
            ->where('mayManage', false)
            // …and the delivery log is withheld: for a platform-wide endpoint it is a
            // record of what happened in every OTHER organization here.
            ->where('deliveries', []));

    // And forged, not merely unrendered.
    foreach (['rotate', 'pause'] as $action) {
        $this->post(route('webhooks.'.$action, $endpoint->id))->assertForbidden();
    }

    $this->delete(route('webhooks.destroy', $endpoint->id))->assertForbidden();

    expect($endpoint->fresh()?->secret_encrypted)->toBe($sealed)
        ->and($endpoint->fresh()?->status)->toBe(EndpointStatus::Active);
})->group('security');

it('refuses a webhook page to an organization admin with no organization at all', function (): void {
    // The nullable reader answers null both for "an environment administrator has not
    // chosen an organization yet" and for "this member has none", and the first means
    // "show the whole environment". Told apart here, because conflating them turns one
    // organization's page into every endpoint in the environment.
    config(['cbox-id.webhooks.verify_url' => false]);
    $subject = app(Subjects::class)->create('orphan-wh@acme.test', 'Orphan', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-orphan-wh'));
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, null, MembershipRole::Owner);

    session([PlatformAuth::SESSION_KEY => $session->id]);

    $this->get(route('webhooks'))->assertForbidden();

    // The detail page too, and it is the one that matters: an unscoped lookup there hands
    // a member with no organization every endpoint in the environment by id — and the
    // secret rotation over each of them.
    $endpoint = app(WebhookRegistry::class)
        ->register($org->id, 'https://hooks.example.test/theirs', ['user.created'])->endpoint;

    $this->get(route('webhooks.show', $endpoint->id))->assertForbidden();
    $this->post(route('webhooks.rotate', $endpoint->id))->assertForbidden();
})->group('security');

it('reveals the signing secret exactly once, and never into the history entry', function (): void {
    /*
     * SHOWN ONCE, and the mechanism is the point.
     *
     * Under Volt this was a protected property, because a public one is dehydrated into
     * the `wire:snapshot` embedded in the DOM, where it outlived the dismissal it was
     * supposed to obey. The Inertia shape has the same hazard wearing different clothes:
     * page props are written into the browser's HISTORY ENTRY, so a secret passed as a
     * prop is retrievable by pressing Back long after the page that showed it has gone.
     *
     * The flash channel is not persisted into history, which is why the credential goes
     * there — and why the second request below must carry nothing at all.
     */
    config(['cbox-id.webhooks.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-webhooks-secret');

    confirmConsoleStepUp();
    $this->from(route('environment.webhooks.create'))
        ->post(route('environment.webhooks.store'), [
            'url' => 'https://hooks.example.test/events',
            'eventTypes' => ['user.created'],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('newSecret');

    $flash = session()->get(SessionKey::FLASH_DATA, []);
    $secret = is_array($flash) ? ($flash['newSecret'] ?? null) : null;

    expect($secret)->toBeString()->not->toBe('');

    $endpoint = WebhookEndpoint::query()->firstOrFail();

    // The page that shows it carries it on the flash channel and NOT in its props.
    // Read off the PAGE OBJECT rather than the session: by the time this request has
    // finished the flash is spent, which is exactly the property being relied on.
    $revealed = $this->get(route('environment.webhooks.show', $endpoint->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('newSecret', $secret));

    expect(json_encode($revealed->inertiaProps()))->toBeString()->not->toContain((string) $secret);

    // …and the next visit carries nothing. One-shot is what makes "copy it now" true.
    $this->get(route('environment.webhooks.show', $endpoint->id))
        ->assertOk()
        ->assertInertiaFlashMissing('newSecret');
})->group('security');

/*
|--------------------------------------------------------------------------
| Roles
|--------------------------------------------------------------------------
| The organization plane had one page: define a role and compose it from the declared
| permission catalog, and nothing else — no rename, no delete, no way to take a
| permission back. The environment plane had list → create → detail with rename,
| permission editing and delete, and no catalog grant at all. The merge is the union of
| all of it.
*/

it('serves roles from one controller on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-roles');

    $this->get(route('environment.roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/roles/index')
            ->where('title', 'Roles'));

    $this->get(route('environment.roles.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/roles/create'));
})->group('security');

it('serves roles from the same controller on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: a role URL is something you send to whoever owns the access.
    actingAsRole(MembershipRole::Owner);

    $this->get(route('roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/roles/index')
            ->where('title', 'Roles'));

    $this->get(route('roles.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/roles/create'));

    expect(Route::has('roles.show'))->toBeTrue()
        ->and(Route::has('roles.create'))->toBeTrue();
})->group('security');

it('defines a role against the organization from the scope', function (): void {
    $orgId = anEnvironmentAdminActingOn('tenant-roles-scoped');

    defineRole([], 'environment.roles')->assertSessionHasNoErrors();

    expect(Role::query()->where('organization_id', $orgId)->where('name', 'Manager')->exists())->toBeTrue();
})->group('security');

it('refuses to define a role before an organization is chosen', function (): void {
    anEnvironmentAdminActingOn('tenant-roles-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    defineRole([], 'environment.roles')->assertSessionHasErrors('name');

    expect(Role::query()->where('name', 'Manager')->exists())->toBeFalse();
})->group('security');

it('keeps environment-wide role definition on the environment plane', function (): void {
    // The environment console only ever wrote environment-wide roles — an organization
    // was never a field on that form, so removing the picker would have taken the
    // capability with it. A role with no organization is assignable inside every tenant
    // in the environment, so it survives as its own explicit choice on this plane alone.
    anEnvironmentAdminActingOn('tenant-roles-wide');

    defineRole(['name' => 'Everywhere', 'environmentWide' => true], 'environment.roles')
        ->assertSessionHasNoErrors();

    expect(Role::query()->whereNull('organization_id')->where('name', 'Everywhere')->exists())->toBeTrue();
})->group('security');

it('refuses an organization admin a role that binds every organization', function (): void {
    // Forged, not clicked: the checkbox is not rendered on this plane, so the refusal has
    // to live in the controller — an environment-wide role can be assigned inside
    // organizations that are not this administrator's.
    actingAsRole(MembershipRole::Owner);

    defineRole(['name' => 'Everywhere', 'environmentWide' => true])
        ->assertSessionHasErrors('environmentWide');

    expect(Role::query()->where('name', 'Everywhere')->exists())->toBeFalse();
})->group('security');

it('gives the organization plane the rename, re-permission and delete it never had', function (): void {
    // All three existed only on the environment plane. A tenant admin could define a role
    // and then never rename it, never take a permission back and never remove it.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $role = app(Roles::class)->define($org->id, 'Support');
    $permission = Permission::query()->create(['name' => 'reports:view', 'description' => 'View reports']);

    $this->from(route('roles.show', $role->id))
        ->patch(route('roles.update', $role->id), ['name' => 'Support Renamed', 'description' => ''])
        ->assertSessionHasNoErrors();

    setRolePermission($role->id, $permission->id, true)->assertSessionHasNoErrors();

    expect(Role::query()->whereKey($role->id)->value('name'))->toBe('Support Renamed')
        ->and(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(1);

    // And back off again — the revoke half, which this plane never had at all.
    setRolePermission($role->id, $permission->id, false);

    expect(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(0);

    $this->delete(route('roles.destroy', $role->id))->assertRedirect(route('roles'));

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
})->group('security');

it('gives the environment plane the catalog grant it never had', function (): void {
    // The other direction. Composing a role out of the keys the apps declared existed
    // only on the organization plane, so an administrator holding the ENTIRE environment
    // could not add a permission to one of its organizations' roles at all.
    $orgId = anEnvironmentAdminActingOn('tenant-roles-grant');
    $role = app(Roles::class)->define($orgId, 'Support');
    $permission = Permission::query()->create(['name' => 'reports:view']);

    setRolePermission($role->id, $permission->id, true, 'environment.roles');

    expect(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(1);
})->group('security');

it('offers the environment plane the picker its own detail page would honour', function (): void {
    // The list's picker was a query of its own, narrower than the detail page's
    // catalogue: on this plane a permission the role's own page would grant was missing
    // from the row, and an environment-wide role could be composed on one page and not
    // the other. One rule now — the row carries what the detail page offers.
    anEnvironmentAdminActingOn('tenant-roles-picker');
    $role = app(Roles::class)->define(null, 'Env-wide support');
    Permission::query()->create(['name' => 'reports:view']);

    $this->get(route('environment.roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roles.0.mayCompose', true)
            ->where('roles.0.offerable.0.name', 'reports:view'));
})->group('security');

it('renders the editor on the environment plane rather than a read-only shell', function (): void {
    // The half of a merge that a PHP-only rewiring silently drops: the markup asked
    // CurrentUser whether to draw the controls, and CurrentUser holds nothing on this
    // plane — so every action would have worked and none of them would have been
    // reachable.
    anEnvironmentAdminActingOn('tenant-roles-editor');
    $role = app(Roles::class)->define(null, 'Support');

    $this->get(route('environment.roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('mayAdminister', true));

    $this->get(route('environment.roles.show', $role->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/roles/show')
            ->where('readOnly', false));
})->group('security');

it('refuses an organization admin another organization\'s role', function (): void {
    // The environment plane resolved a role on its primary key alone, which is right for
    // an administrator who holds the environment. Serving the same controller to a tenant
    // would have handed them every other tenant's roles by id — readable, renameable and
    // deletable, with every holder of the role losing its access.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-roles'));
    $foreign = app(Roles::class)->define($other->id, 'Not yours');
    app(Roles::class)->define($org->id, 'Mine');

    $this->get(route('roles.show', $foreign->id))->assertNotFound();

    $this->get(route('roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'roles',
            fn (Collection $rows): bool => $rows->pluck('name')->doesntContain('Not yours'),
        ));

    // And forged past the page: the id arrives in the URL of every mutation, so each one
    // re-resolves it inside the gate rather than trusting what it was handed.
    $this->delete(route('roles.destroy', $foreign->id))->assertNotFound();
    $this->patch(route('roles.update', $foreign->id), ['name' => 'Taken', 'description' => ''])->assertNotFound();

    expect(Role::query()->whereKey($foreign->id)->value('name'))->toBe('Not yours');

    // The catalog grant is scoped the same way — the picker is not rendered for a role
    // that is not this organization's, and the endpoint refuses one anyway.
    $permission = Permission::query()->create(['name' => 'reports:view']);
    setRolePermission($foreign->id, $permission->id, true)->assertNotFound();

    expect(DB::table('role_permission')->where('role_id', $foreign->id)->count())->toBe(0);
})->group('security');

it('shows an organization the environment-wide role it cannot change', function (): void {
    // The organization must SEE it: an environment-wide role can be assigned to its
    // people and carries permissions into its apps. The detail page is new to this plane,
    // so it gets the read-only treatment rather than a delete button for a role every
    // other organization in the environment also holds.
    actingAsRole(MembershipRole::Owner);
    $role = app(Roles::class)->define(null, 'Env-wide support');

    $this->get(route('roles.show', $role->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('role.name', 'Env-wide support')
            // Read-only, and NOT because an app declares it — the two say different
            // sentences on the page, and only one of them is true here.
            ->where('readOnly', true)
            ->where('declaredByApp', false));

    $this->delete(route('roles.destroy', $role->id))->assertNotFound();

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
})->group('security');

it('refuses everyone the role an app declares', function (): void {
    // An app-declared role is the declaring app's source of truth on BOTH planes, so the
    // refusal cannot be the organization fence — an environment administrator passes that
    // and must still be refused. 403 rather than 404: this role is legitimately visible,
    // it is simply not anybody's here to change.
    anEnvironmentAdminActingOn('tenant-roles-manifest');
    $role = app(Roles::class)->define(null, 'App-owned');
    $role->forceFill(['source' => RoleSource::Manifest])->save();

    $this->get(route('environment.roles.show', $role->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('readOnly', true)
            ->where('declaredByApp', true));

    $this->patch(route('environment.roles.update', $role->id), ['name' => 'Taken', 'description' => ''])
        ->assertForbidden();
    $this->delete(route('environment.roles.destroy', $role->id))->assertForbidden();

    expect(Role::query()->whereKey($role->id)->value('name'))->toBe('App-owned');
})->group('security');

it('never lets a tenant tick a permission its apps kept internal', function (): void {
    // tenant_assignable is how an app publishes a key WITHOUT offering it to the tenants
    // that use it. The organization plane honoured that in its catalog picker; the
    // environment plane's permission editor — which this plane now has — never had to,
    // because its administrator holds the environment. Enforced in the controller,
    // because a checkbox that is not drawn is not a gate.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $role = app(Roles::class)->define($org->id, 'Support');
    $internal = Permission::query()->create(['name' => 'billing:refund', 'tenant_assignable' => false]);

    $this->get(route('roles.show', $role->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'catalog',
            fn (Collection $rows): bool => $rows->pluck('name')->doesntContain('billing:refund'),
        ));

    setRolePermission($role->id, $internal->id, true);

    expect(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(0);
})->group('security');

it('never lets a role hold another app\'s key', function (): void {
    // A role scoped to one app may hold that app's permissions and the unscoped ones. The
    // catalogue this administrator may assign from is wider than that — it spans every app
    // in reach — so the fence is on the WRITE, not on the list the picker was drawn from.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $mine = app(ClientRegistry::class)->register(new NewClient('Mine', organizationId: $org->id));
    $theirs = app(ClientRegistry::class)->register(new NewClient('Theirs', organizationId: $org->id));

    $role = app(Roles::class)->define($org->id, 'Support', null, $mine->client->client_id);
    $foreign = Permission::query()->create([
        'name' => 'theirs:read',
        'client_id' => $theirs->client->client_id,
        'tenant_assignable' => true,
    ]);

    setRolePermission($role->id, $foreign->id, true);

    expect(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(0);
})->group('security');

it('refuses an organization admin with no organization at all a roles page', function (): void {
    // The nullable reader answers null both for "an environment administrator has not
    // chosen an organization yet" and for "this member has none", and the first means
    // "show the whole environment". Told apart here, because conflating them turns an
    // organization page into every role in the environment.
    $subject = app(Subjects::class)->create('orphan-roles@acme.test', 'Orphan', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-orphan-roles'));
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, null, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $this->get(route('roles'))->assertForbidden();
})->group('security');

/*
|--------------------------------------------------------------------------
| Apps & API keys (OAuth clients)
|--------------------------------------------------------------------------
| The organization plane had one page: an inline create form, an inline roles-manifest
| panel with save + sync now, and delete. The environment plane had list → create →
| detail with edit, secret rotation and delete — and always registered an app the
| ENVIRONMENT owns, because it had no organization on its form at all. The merge is the
| union: an environment admin gains the manifest, an organization admin gains editing
| and rotation, and the environment-owned app survives as an explicit choice.
*/

it('serves apps from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-apps');

    $this->get(route('environment.clients'))->assertOk()->assertSee('API keys');
    confirmConsoleStepUp();
    $this->get(route('environment.clients.create'))->assertOk();
})->group('security');

it('serves apps from the same component on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: an app has a lifecycle worth linking to, and the reveal-once client
    // secret needs somewhere to land that is not the form you just submitted.
    actingAsRole(MembershipRole::Owner);

    // Driven at the component, because actingAsRole() populates CurrentUser the way the
    // middleware would rather than minting a session cookie — an HTTP request would just
    // bounce to sign-in and prove nothing about the page.
    $this->get(route('clients'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/clients/index')
            ->where('title', 'Apps & API keys'));
    confirmConsoleStepUp();
    $this->get(route('clients.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/clients/create'));

    expect(Route::has('clients.show'))->toBeTrue()
        ->and(Route::has('clients.create'))->toBeTrue();
})->group('security');

it('registers an app against the organization from the scope', function (): void {
    // The create form carried no organization on either plane — one implied the member's,
    // the other implied none at all — so the same page registered the app somewhere
    // different depending which door you came through.
    $orgId = anEnvironmentAdminActingOn('tenant-apps-scoped');

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Support Portal',
        'redirectUris' => 'https://portal.example.test/callback',
    ], 'environment.clients')->assertSessionHasNoErrors();

    expect(Client::query()->where('organization_id', $orgId)->where('name', 'Support Portal')->exists())->toBeTrue();
})->group('security');

it('refuses to register an app before an organization is chosen', function (): void {
    anEnvironmentAdminActingOn('tenant-apps-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    confirmConsoleStepUp();
    // Refused outright rather than reported on a field: with no organization resolved
    // there is nowhere for this app to land, and a downstream default picking one would
    // register it against a tenant nobody named.
    registerApp([
        'name' => 'Support Portal',
        'redirectUris' => 'https://portal.example.test/callback',
    ], 'environment.clients')->assertForbidden();

    expect(Client::query()->where('name', 'Support Portal')->exists())->toBeFalse();
})->group('security');

it('keeps environment-owned registration on the environment plane', function (): void {
    // The picker's hidden capability, invisible here because there was no picker: this
    // plane's form had no organization field, so every app it registered belonged to the
    // environment rather than to a tenant. Marked first-party such an app skips the
    // consent screen for EVERY organization and appears in each of their launchers, so it
    // survives as its own explicit choice rather than as the silent default.
    anEnvironmentAdminActingOn('tenant-apps-wide');

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Platform Console',
        'environmentWide' => true,
        'redirectUris' => 'https://console.example.test/callback',
    ], 'environment.clients')->assertSessionHasNoErrors();

    expect(Client::query()->whereNull('organization_id')->where('name', 'Platform Console')->exists())->toBeTrue();
})->group('security');

it('refuses environment-owned registration from the organization plane', function (): void {
    // A first-party app with no organization skips consent for every tenant in the
    // environment. The checkbox is not rendered on this plane; the property is still
    // client-settable, so the refusal is in the action.
    actingAsRole(MembershipRole::Owner);

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Everyone\'s App',
        'environmentWide' => true,
        'redirectUris' => 'https://everyone.example.test/callback',
    ])->assertForbidden();

    expect(Client::query()->whereNull('organization_id')->exists())->toBeFalse();
})->group('security');

it('gives the organization plane the edit and rotate it never had', function (): void {
    // Both actions existed only on the environment plane: a tenant admin could create an
    // app and delete it, and could do nothing whatever in between — a leaked secret meant
    // deleting the app and re-integrating from scratch.
    [, $org] = actingAsRole(MembershipRole::Owner);

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Support Portal',
        type: ClientType::Confidential,
        redirectUris: ['https://portal.example.test/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $org->id,
    ))->client;

    $before = Client::query()->whereKey($client->id)->value('secret_hash');

    confirmConsoleStepUp();

    $showing = (array) $this->get(route('clients.show', $client->id))
        ->assertOk()
        ->inertiaProps('client');

    $this->from(route('clients.show', $client->id))
        ->patch(route('clients.update', $client->id), [
            'name' => 'Support Portal (EU)',
            'redirectUris' => 'https://eu.portal.example.test/callback',
            'postLogoutRedirectUris' => $showing['postLogoutRedirectUris'],
            'scopes' => $showing['scopes'],
            'customScopes' => $showing['customScopes'],
        ])
        ->assertSessionHasNoErrors();

    $this->from(route('clients.show', $client->id))
        ->post(route('clients.rotate', $client->id))
        ->assertSessionHasNoErrors();

    $fresh = $client->fresh();

    expect($fresh?->name)->toBe('Support Portal (EU)')
        ->and($fresh?->redirect_uris)->toBe(['https://eu.portal.example.test/callback'])
        ->and($fresh?->secret_hash)->not->toBe($before);
})->group('security');

it('gives the environment plane the roles manifest it never had', function (): void {
    // The manifest — where an app publishes the roles it understands — existed only on
    // the organization plane, so the administrator holding every app in the environment
    // could neither point one at its manifest nor sync it.
    $orgId = anEnvironmentAdminActingOn('tenant-apps-manifest');

    // A stand-in fetcher, so this proves the console wiring rather than the network.
    app()->instance(ManifestFetcher::class, new class implements ManifestFetcher
    {
        public function fetch(string $url): Manifest
        {
            return new Manifest('1', [], [new DeclaredRole('support', 'Support', null, [])]);
        }
    });

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Support Portal',
        type: ClientType::Confidential,
        redirectUris: ['https://portal.example.test/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $orgId,
    ))->client;

    $this->from(route('environment.clients.show', $client->id))
        ->put(route('environment.clients.manifest', $client->id), [
            'manifestUrl' => 'https://portal.example.test/.well-known/cbox-authz',
        ])
        ->assertSessionHasNoErrors();

    $this->from(route('environment.clients.show', $client->id))
        ->post(route('environment.clients.sync', $client->id))
        ->assertSessionHasNoErrors();

    expect($client->fresh()?->manifest_url)->toBe('https://portal.example.test/.well-known/cbox-authz')
        ->and(Role::query()->where('client_id', $client->client_id)->where('key', 'support')->exists())->toBeTrue();
})->group('security');

it('refuses an organization admin another organization\'s app', function (): void {
    // The sharpest case in the whole refactor. The environment plane resolved a client on
    // the primary key alone, which was safe only while an environment administrator was
    // the sole caller. Serving the same page to a tenant admin would hand them any other
    // tenant's app by id — and on THIS page that is a live credential to rotate out from
    // under someone else's production deployment, and an application to delete.
    actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-apps'));

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Not Yours',
        type: ClientType::Confidential,
        redirectUris: ['https://other.example.test/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $other->id,
    ))->client;

    $before = Client::query()->whereKey($client->id)->value('secret_hash');

    $this->get(route('clients'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('clients', fn (Collection $rows): bool => $rows
                ->pluck('name')
                ->doesntContain('Not Yours')));

    // 404 rather than 403: the caller was not entitled to learn the app exists. And EVERY
    // write asked directly, because each is its own request now — a refusal on the page
    // that would have drawn the button is not a refusal of the button.
    $this->get(route('clients.show', $client->id))->assertNotFound();
    $this->post(route('clients.rotate', $client->id))->assertNotFound();
    $this->delete(route('clients.destroy', $client->id))->assertNotFound();

    expect(Client::query()->whereKey($client->id)->value('secret_hash'))->toBe($before)
        ->and(Client::query()->whereKey($client->id)->exists())->toBeTrue();
})->group('security');

it('shows an organization the platform app it cannot change', function (): void {
    // A platform-owned first-party app appears in the tenant's launcher and skips its
    // consent screen, so the tenant must be able to SEE it. It is not theirs to rotate or
    // delete, and the detail page is new to this plane — so it inherits the read-only
    // treatment rather than offering a tenant admin a delete button for the platform's
    // own app.
    actingAsRole(MembershipRole::Owner);

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Cbox Billing',
        type: ClientType::Confidential,
        redirectUris: ['https://billing.example.test/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        firstParty: true,
    ))->client;

    $this->get(route('clients.show', $client->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('client.name', 'Cbox Billing')
            // The controls are not offered rather than offered and refused.
            ->where('mayManage', false));

    $this->post(route('clients.rotate', $client->id))->assertForbidden();
    $this->delete(route('clients.destroy', $client->id))->assertForbidden();

    expect(Client::query()->whereKey($client->id)->exists())->toBeTrue();
})->group('security');

it('refuses an organization admin with no organization at all an app list', function (): void {
    // The nullable reader answers null both for "an environment administrator has not
    // chosen an organization yet" and for "this member has none", and the first means
    // "show the whole environment". Told apart here, because conflating them turns an
    // organization page into every app in the environment — client ids and all.
    $subject = app(Subjects::class)->create('orphan-apps@acme.test', 'Orphan', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-orphan-apps'));
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, null, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $this->get(route('clients'))->assertForbidden();
})->group('security');

it('reveals a new app\'s secret exactly once, and never into the history entry', function (): void {
    /*
     * The secret is minted once and shown once, and WHERE it travels is the whole of it.
     *
     * Under Volt this was a protected property, because a public one is dehydrated into
     * the `wire:snapshot` embedded in the DOM and outlives the banner's dismissal. The
     * Inertia shape has the same hazard wearing different clothes: page props are written
     * into the browser's HISTORY ENTRY, so a secret passed as a prop is retrievable by
     * pressing Back long after the page that showed it has gone.
     *
     * The flash channel is not persisted into history — which is why the credential goes
     * there, and why the second visit below must carry nothing at all.
     */
    $orgId = anEnvironmentAdminActingOn('tenant-apps-secret');

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Secretive App',
        'redirectUris' => 'https://secretive.example.test/callback',
    ], 'environment.clients')->assertSessionHasNoErrors()->assertInertiaFlash('revealedSecret');

    $client = Client::query()->where('name', 'Secretive App')->firstOrFail();

    $flash = session()->get(SessionKey::FLASH_DATA, []);
    $secret = is_array($flash) ? ($flash['revealedSecret'] ?? null) : null;

    expect($secret)->toBeString()->toStartWith('csec_');

    $revealed = $this->get(route('environment.clients.show', $client->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('revealedSecret', $secret));

    expect(json_encode($revealed->inertiaProps()))->toBeString()->not->toContain('csec_');

    // …and the next visit carries nothing. One-shot is what makes "copy it now" true.
    $this->get(route('environment.clients.show', $client->id))
        ->assertOk()
        ->assertInertiaFlashMissing('revealedSecret');

    expect($client->organization_id)->toBe($orgId);
})->group('security');

it('holds an unverified organization admin back from registering an app, and only them', function (): void {
    // A social sign-in creates the account immediately with an address only the provider
    // vouched for, and an app is a durable object other people will trust — so the
    // organization plane holds it. The environment plane must NOT be held by the same
    // gate: an environment administrator authenticates as an account member, where there
    // is no subject to read, so the gate answers "unverified" for every one of them and
    // would lock the plane out of registering apps at all.
    // The environment plane first, while no subject session exists: the scope reads the
    // subject store before the account store, so signing in as a member below would move
    // this same request onto the other plane.
    $orgId = anEnvironmentAdminActingOn('tenant-apps-unverified');

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Env Admin App',
        'redirectUris' => 'https://envadmin.example.test/callback',
    ], 'environment.clients')->assertSessionHasNoErrors();

    expect(Client::query()->where('organization_id', $orgId)->where('name', 'Env Admin App')->exists())->toBeTrue();

    actingAsRole(MembershipRole::Owner, emailVerified: false);

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Unverified App',
        'redirectUris' => 'https://unverified.example.test/callback',
    ])->assertForbidden();

    expect(Client::query()->where('name', 'Unverified App')->exists())->toBeFalse();
})->group('security');

it('never writes a rotated client secret into the page props', function (): void {
    // The same property as the reveal above, on the OTHER way a plaintext appears. A
    // rotation is where an administrator is most likely to be sharing a screen, and a
    // secret in props is a secret in the history entry.
    anEnvironmentAdminActingOn('tenant-apps-snapshot');

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Rotating App',
        type: ClientType::Confidential,
        redirectUris: ['https://rotating.example.test/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: app(ConsoleScope::class)->requireOrganizationId(),
    ))->client;

    confirmConsoleStepUp();

    $this->from(route('environment.clients.show', $client->id))
        ->post(route('environment.clients.rotate', $client->id))
        ->assertInertiaFlash('revealedSecret');

    $revealed = $this->get(route('environment.clients.show', $client->id))->assertOk();

    // On the flash channel, which the client does not persist into history…
    $revealed->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('revealedSecret'));

    // …and NOT in the props, which it does.
    expect(json_encode($revealed->inertiaProps()))->toBeString()->not->toContain('csec_');
})->group('security');

/*
|--------------------------------------------------------------------------
| Sync users in (inbound directories)
|--------------------------------------------------------------------------
| The worst-drifted pair in the console. The organization plane had one page that could
| register a SCIM directory, connect Google Workspace or Microsoft Entra as PULL
| directories, mint an Admin Portal setup link, and map groups onto roles. The
| environment plane had list → create → detail with rename, token rotation, pause and
| delete — and SCIM only, so an administrator who holds every organization in the
| environment could not connect either of the two providers the product ships connectors
| for. The merge is the union of all of it.
*/

it('serves sync users in from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-directories');

    $this->get(route('environment.directories'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/directories/index')
            ->where('title', 'Sync users in')
            // The view half. Rewiring only the PHP leaves an environment administrator a
            // read-only shell: the controls were gated on `CurrentUser::isAdmin()`, a
            // question only the organization plane can answer, so on this plane they
            // simply vanished. `mayAdminister` is asked of the SCOPE, which answers on
            // both.
            ->where('mayAdminister', true)
            ->where('entitled', true));

    confirmConsoleStepUp();
    $this->get(route('environment.directories.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/directories/create'));
})->group('security');

it('serves sync users in from the same component on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: the reveal-once bearer token needs somewhere to land that is not the row
    // you just submitted, and a directory URL is something you send to whoever runs the
    // identity provider.
    actingAsRole(MembershipRole::Owner);

    $this->get(route('directories'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/directories/index')
            ->where('title', 'Sync users in'));
    confirmConsoleStepUp();
    $this->get(route('directories.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/directories/create'));

    expect(Route::has('directories.show'))->toBeTrue()
        ->and(Route::has('directories.create'))->toBeTrue();
})->group('security');

it('offers SCIM and both pull providers on both planes', function (): void {
    // THE finding that started the console merge. The organization console offered
    // Google Workspace and Microsoft Entra; the environment console offered SCIM alone,
    // so an environment administrator could not connect either of the two directories
    // this product ships connectors for — and Google has no SCIM support at all, which
    // made pull the ONLY path to it. Both planes now offer what the enum publishes,
    // because the enum is the public contract.
    $expected = array_map(
        fn (DirectoryProvider $provider): string => $provider->label(),
        DirectoryProvider::cases(),
    );

    anEnvironmentAdminActingOn('tenant-dir-providers');
    confirmConsoleStepUp();
    $environment = collect((array) $this->get(route('environment.directories.create'))
        ->assertOk()
        ->inertiaProps('providers'))->pluck('label');

    actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();
    $organization = collect((array) $this->get(route('directories.create'))
        ->assertOk()
        ->inertiaProps('providers'))->pluck('label');

    // The same set on both planes, asserted as a set rather than by looking for each
    // label somewhere in a document — which would also pass for a page that named a
    // provider it did not offer.
    foreach ($expected as $label) {
        expect($environment)->toContain($label)
            ->and($organization)->toContain($label);
    }
})->group('security');

it('shows each pull provider its own directory guide, on both planes', function (): void {
    // The gap the unified catalogue closes. The steps for connecting Google as a
    // DIRECTORY existed in the framework — beside the steps for connecting Google for
    // SIGN-IN — and the two registries naming the same provider shared nothing, so this
    // page could reach neither. An administrator who had just finished the sign-in half
    // got an empty credential box and no hint that a directory wants a service account
    // rather than the OAuth client they had in front of them.
    anEnvironmentAdminActingOn('tenant-dir-guide');
    confirmConsoleStepUp();
    $planes = [(array) $this->get(route('environment.directories.create'))->assertOk()->inertiaProps('providers')];

    actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();
    $planes[] = (array) $this->get(route('directories.create'))->assertOk()->inertiaProps('providers');

    foreach ([DirectoryProvider::GoogleWorkspace, DirectoryProvider::MicrosoftEntra] as $provider) {
        $template = ProviderCatalog::forDirectory($provider);
        $setup = $template?->directory;

        expect($setup)->not->toBeNull($provider->value.' has no catalogue entry to show');

        foreach ($planes as $providers) {
            $offered = collect($providers)->firstWhere('value', $provider->value);

            expect($offered['setup']['docs'] ?? null)->toBe($setup->documentationUrl)
                ->and($offered['setup']['steps'] ?? [])->toBe($setup->setupSteps)
                // And it is the DIRECTORY guide, not the sign-in one. Both exist for this
                // provider and they describe unrelated jobs; showing the wrong one is
                // worse than showing none, because it is followed to the end before it
                // fails.
                ->and($offered['setup']['steps'] ?? [])->not->toContain($template->setupSteps[0]);
        }
    }
})->group('security');

it('registers a directory against the organization from the scope', function (): void {
    // The create form used to carry its own organization picker on the environment plane
    // and none on the organization plane — the second place the answer lived, validated
    // differently in each.
    $orgId = anEnvironmentAdminActingOn('tenant-dir-scoped');

    confirmConsoleStepUp();
    registerDirectory(['name' => 'Acme Okta SCIM'], 'environment.directories')->assertSessionHasNoErrors();

    expect(Directory::query()->where('organization_id', $orgId)->exists())->toBeTrue();
})->group('security');

it('refuses to register a directory before an organization is chosen', function (): void {
    anEnvironmentAdminActingOn('tenant-dir-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    confirmConsoleStepUp();
    // Refused outright rather than reported on a field: with no organization resolved
    // there is nowhere for this directory to land.
    registerDirectory(['name' => 'Acme Okta SCIM'], 'environment.directories')->assertForbidden();

    expect(Directory::query()->exists())->toBeFalse();
})->group('security');

it('tells an unchosen environment administrator to pick an organization, not to call their account team', function (): void {
    // `entitled()` answers false for "no organization chosen" as well as for "this
    // organization has no plan", and the two need different words. Sending an
    // administrator who holds the whole environment to their account team over a picker
    // they have simply not touched is a dead end dressed as an answer.
    config(['cbox-id.entitlements.mode' => 'metered']);
    anEnvironmentAdminActingOn('tenant-dir-unentitled');
    session()->forget(ConsoleScope::SELECTION_KEY);

    $this->get(route('environment.directories'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // Two different problems with two different fixes, and the props say which:
            // nothing is chosen, so nothing is unentitled yet.
            ->where('organizationChosen', false)
            ->where('entitled', false));
})->group('security');

it('connects a pull directory on the environment plane', function (): void {
    // The capability the environment plane never had. Verified against the connector
    // before anything is stored, so a wrong key fails here rather than at 3am.
    $orgId = anEnvironmentAdminActingOn('tenant-dir-pull');

    app()->instance(DirectoryConnectors::class, new DirectoryConnectors([
        new FakeDirectoryConnector(DirectoryProvider::GoogleWorkspace),
        new FakeDirectoryConnector(DirectoryProvider::MicrosoftEntra),
    ]));

    confirmConsoleStepUp();
    connectDirectory([
        'provider' => DirectoryProvider::GoogleWorkspace->value,
        'googleServiceAccountJson' => (string) json_encode([
            'client_email' => 'sync@acme.iam.gserviceaccount.com',
            'private_key' => '-----BEGIN PRIVATE KEY-----key-----END PRIVATE KEY-----',
        ]),
        'googleAdminEmail' => 'admin@acme.test',
    ], 'environment.directories')->assertSessionHasNoErrors();

    $directory = Directory::query()->where('organization_id', $orgId)->firstOrFail();

    expect($directory->provider)->toBe(DirectoryProvider::GoogleWorkspace)
        // Sealed at rest, bound to the directory id — never the plaintext map.
        ->and($directory->credentials)->not->toBeNull()
        ->and($directory->credentials)->not->toContain('BEGIN PRIVATE KEY');
})->group('security');

it('refuses a pull directory whose credentials the provider rejects', function (): void {
    anEnvironmentAdminActingOn('tenant-dir-pull-bad');

    app()->instance(DirectoryConnectors::class, new DirectoryConnectors([
        new FakeDirectoryConnector(DirectoryProvider::MicrosoftEntra, verifies: false),
    ]));

    confirmConsoleStepUp();
    connectDirectory([
        'provider' => DirectoryProvider::MicrosoftEntra->value,
        'entraTenantId' => 'tenant',
        'entraClientId' => 'client',
        'entraClientSecret' => 'shh',
    ], 'environment.directories')->assertSessionHasErrors('credentials');

    expect(Directory::query()->exists())->toBeFalse();
})->group('security');

it('gives the organization plane the lifecycle it never had', function (): void {
    // Rename, rotate, pause and delete existed only on the environment plane. A tenant
    // admin whose SCIM token leaked had no way to rotate it from their own console.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $directory = app(Directories::class)->register($org->id, 'HR')->directory;
    $originalHash = $directory->bearer_token_hash;

    confirmConsoleStepUp();

    $from = route('directories.show', $directory->id);

    $this->from($from)->patch(route('directories.update', $directory->id), ['name' => 'HR Renamed'])
        ->assertSessionHasNoErrors();
    $this->from($from)->post(route('directories.rotate', $directory->id))->assertRedirect();
    $this->from($from)->post(route('directories.toggle', $directory->id))->assertRedirect();

    $directory->refresh();
    expect($directory->name)->toBe('HR Renamed')
        ->and($directory->bearer_token_hash)->not->toBe($originalHash)
        ->and($directory->status)->toBe(DirectoryStatus::Paused);

    $this->delete(route('directories.destroy', $directory->id))->assertRedirect();
    expect(Directory::query()->whereKey($directory->id)->exists())->toBeFalse();
})->group('security');

it('reveals a directory bearer token exactly once, on both planes', function (): void {
    /*
     * Only the organization console had a Dismiss, and the banner holds a live credential
     * in plaintext — the environment plane, whose administrator holds every organization
     * here, had no way to take it off the screen.
     *
     * There is nothing to dismiss now, which is the stronger answer: the token rides the
     * flash channel, so it is on the ONE render that follows the mint and gone from the
     * next. A dismissal was a control that had to be pressed for the credential to leave.
     */
    anEnvironmentAdminActingOn('tenant-dir-token');

    confirmConsoleStepUp();
    registerDirectory(['name' => 'Acme Okta SCIM'], 'environment.directories')
        ->assertInertiaFlash('newToken');

    $directory = Directory::query()->firstOrFail();

    $this->get(route('environment.directories.show', $directory->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('newToken'));

    $this->get(route('environment.directories.show', $directory->id))
        ->assertOk()
        ->assertInertiaFlashMissing('newToken');
})->group('security');

it('refuses an organization admin another organization\'s directory', function (): void {
    // The escalation this merge could have shipped. The environment plane resolved a
    // directory on its primary key alone, which was safe only while an environment
    // administrator was its sole caller. Served to a tenant admin that lookup hands over
    // every other tenant's directory by id — and here that is not a disclosure but a
    // takeover: regenerateToken would mint a live SCIM bearer token for a directory that
    // provisions somebody else's users.
    actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-directories'));
    $directory = app(Directories::class)->register($other->id, 'Not yours')->directory;
    $originalHash = $directory->bearer_token_hash;

    $this->get(route('directories.show', $directory->id))->assertNotFound();
    $this->post(route('directories.rotate', $directory->id))->assertNotFound();
    $this->delete(route('directories.destroy', $directory->id))->assertNotFound();

    $this->get(route('directories'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('directories', fn (Collection $rows): bool => $rows
                ->pluck('name')
                ->doesntContain('Not yours')));

    expect($directory->fresh()?->bearer_token_hash)->toBe($originalHash);
})->group('security');

it('refuses a group mapping that belongs to another directory', function (): void {
    // Both mapping actions take a group id straight off the page. The organization
    // console scoped neither by directory and the environment console scoped only the
    // map half, so an id belonging to another directory could be unmapped through a
    // directory the caller does hold.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $mine = app(Directories::class)->register($org->id, 'Mine')->directory;
    $theirs = app(Directories::class)->register($org->id, 'Theirs')->directory;

    $foreign = DirectoryGroup::query()->create([
        'directory_id' => $theirs->id,
        'external_id' => 'grp-1',
        'display_name' => 'Engineering',
    ]);
    $role = app(Roles::class)->define($org->id, 'Engineer');

    $this->from(route('directories.show', $mine->id))
        ->post(route('directories.map', $mine->id), [
            'group' => $foreign->id,
            'role' => $role->id,
            'mapped' => true,
        ])
        ->assertNotFound();

    expect(GroupRoleMapping::query()->exists())->toBeFalse();
})->group('security');

it('refuses an organization admin with no organization at all a directories page', function (): void {
    // The nullable reader answers null both for "an environment administrator has not
    // chosen an organization yet" and for "this member has none", and the first means
    // "show the whole environment". Conflating them turns an organization page into every
    // directory in the environment.
    $subject = app(Subjects::class)->create('orphan-dir@acme.test', 'Orphan', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-orphan-dir'));
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, null, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $this->get(route('directories'))->assertForbidden();
})->group('security');

/*
|--------------------------------------------------------------------------
| Single sign-on (federated connections)
|--------------------------------------------------------------------------
| The organization plane had one page: an inline create form, row-level activate,
| domain verification with the capture gate, SAML metadata import, and the Admin Portal
| invite that hands SSO setup to an external IT admin. It could not edit, disable or
| delete a connection at all. The environment plane had list → create → detail which
| could do all three — and had no domains, no portal invite and no entitlement check.
| The merge is the union of all of it.
*/

/** A SAML connection with a complete, sealed config, so the detail page can round-trip it. */
function aSamlConnection(string $organizationId, string $name = 'Corporate SAML'): Connection
{
    return app(Connections::class)->create($organizationId, ConnectionType::Saml, $name, [
        'idp_entity_id' => 'https://idp.corp/metadata',
        'idp_sso_url' => 'https://idp.corp/sso',
        'idp_x509cert' => '-----BEGIN CERTIFICATE-----MIIB-----END CERTIFICATE-----',
        'sp_entity_id' => 'https://sp.acme/metadata',
        'sp_acs_url' => 'https://sp.acme/acs',
    ]);
}

it('serves single sign-on from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-sso');

    $this->get(route('environment.connections'))->assertOk()->assertSee('Single sign-on');
    $this->get(route('environment.connections.create'))->assertOk();
})->group('security');

it('serves single sign-on from the same component on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: a connection URL is something you send to whoever runs the identity
    // provider.
    actingAsRole(MembershipRole::Owner);

    $this->get(route('connections'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/connections/index')
            ->where('title', 'Single sign-on'));
    $this->get(route('connections.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('console/connections/create'));

    expect(Route::has('connections.show'))->toBeTrue()
        ->and(Route::has('connections.create'))->toBeTrue();
})->group('security');

it('creates a connection against the organization from the scope', function (): void {
    // The create form used to carry its own organization picker on the environment plane
    // and none on the organization plane — the second place the answer lived, validated
    // differently in each.
    $orgId = anEnvironmentAdminActingOn('tenant-sso-scoped');

    createConnection(['name' => 'Acme Okta'], 'environment.connections')->assertSessionHasNoErrors();

    expect(Connection::query()->where('organization_id', $orgId)->where('name', 'Acme Okta')->exists())->toBeTrue();
})->group('security');

it('refuses to create a connection before an organization is chosen', function (): void {
    anEnvironmentAdminActingOn('tenant-sso-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    // Refused outright rather than reported on a field: with no organization resolved
    // there is nowhere for this connection to land.
    createConnection(['name' => 'Acme Okta'], 'environment.connections')->assertForbidden();

    expect(Connection::query()->exists())->toBeFalse();
})->group('security');

it('gives the organization plane the edit, disable and delete it never had', function (): void {
    // The reason an earlier attempt at this merge was reverted: it routed both planes at
    // the organization component, which had none of these three. Losing them means a
    // tenant admin cannot correct a rotated certificate, cannot switch a misconfigured
    // IdP off, and cannot remove one at all.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $connection = aSamlConnection($org->id);

    $showing = (array) $this->get(route('connections.show', $connection->id))
        ->assertOk()
        ->inertiaProps('connection.config');

    $from = route('connections.show', $connection->id);

    $this->from($from)->patch(route('connections.update', $connection->id), [
        ...$showing,
        'name' => 'Corporate SAML (renamed)',
    ])->assertSessionHasNoErrors();
    expect(Connection::query()->whereKey($connection->id)->value('name'))->toBe('Corporate SAML (renamed)');

    $this->from($from)->post(route('connections.activate', $connection->id))->assertRedirect();
    expect($connection->fresh()?->isActive())->toBeTrue();

    $this->from($from)->post(route('connections.disable', $connection->id))->assertRedirect();
    expect($connection->fresh()?->status)->toBe(Cbox\Id\Federation\Enums\ConnectionStatus::Inactive);

    $this->delete(route('connections.destroy', $connection->id))->assertRedirect();
    expect(Connection::query()->whereKey($connection->id)->exists())->toBeFalse();
})->group('security');

it('gives the environment plane domain verification and the Admin Portal invite', function (): void {
    // The other half of the union. Neither existed on the environment console, so an
    // operator configuring SSO for a tenant could not prove the tenant's email domain —
    // which is the thing that routes their people to the IdP at all.
    $orgId = anEnvironmentAdminActingOn('tenant-sso-domains');

    // Upper-case → normalized to lowercase.
    $this->from(route('environment.connections'))
        ->post(route('environment.connections.domains.store'), ['domain' => 'ACME.com'])
        ->assertSessionHasNoErrors();

    expect(VerifiedDomain::query()->where('organization_id', $orgId)->where('domain', 'acme.com')->exists())->toBeTrue();

    // The portal link rides on the FLASH CHANNEL, not in props: it admits its holder to
    // this tenant's SSO setup with no account at all.
    $this->from(route('environment.connections'))
        ->post(route('environment.connections.invite'))
        ->assertInertiaFlash('portalUrl');

    $flash = session()->get(SessionKey::FLASH_DATA, []);

    expect(is_array($flash) ? ($flash['portalUrl'] ?? '') : '')->toContain('/setup/');
})->group('security');

it('attributes an Admin Portal link to the environment administrator who minted it', function (): void {
    // actorId(), not CurrentUser::id(): the environment plane has no subject session, so
    // the organization page's reader would have recorded '' as the creator of a link
    // that hands SSO configuration to an outsider.
    $orgId = anEnvironmentAdminActingOn('tenant-sso-actor');
    $actor = app(ConsoleScope::class)->actorId();

    $this->from(route('environment.connections'))
        ->post(route('environment.connections.invite'))
        ->assertSessionHasNoErrors();

    expect($actor)->not->toBe('')
        ->and(AdminPortalLink::query()->where('organization_id', $orgId)->value('created_by'))->toBe($actor);
})->group('security');

it('refuses an organization admin another organization\'s connection', function (): void {
    // The escalation this merge had to close. The environment component resolved a
    // connection on its primary key alone, which was safe only while an environment
    // administrator was its sole caller. Serving it to a tenant admin unscoped would hand
    // them saveConfig on every connection in the environment — rewriting where another
    // company's people authenticate, over a sealed config holding that company's client
    // secret and signing certificate.
    actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-sso'));
    $theirs = aSamlConnection($other->id, 'Not yours');

    // The refusal is at mount, which is why there is no forged-action case below it: the
    // page never yields a snapshot, so saveConfig cannot be reached to be refused. Every
    // later read re-resolves through the same scoped query rather than trusting the id
    // the mount accepted.
    $this->get(route('connections.show', $theirs->id))->assertNotFound();
    $this->patch(route('connections.update', $theirs->id), ['name' => 'Hijacked'])->assertNotFound();
    $this->post(route('connections.activate', $theirs->id))->assertNotFound();
    $this->delete(route('connections.destroy', $theirs->id))->assertNotFound();

    $this->get(route('connections'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connections', fn (Collection $rows): bool => $rows
                ->pluck('name')
                ->doesntContain('Not yours')));

    expect(Connection::query()->whereKey($theirs->id)->value('name'))->toBe('Not yours');
})->group('security');

it('refuses another organization\'s domain to an organization admin', function (): void {
    // The same escalation on the domain half: capture on a domain you do not own would
    // force that company's people through YOUR identity provider.
    actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-sso-domains'));
    $foreign = app(DomainVerification::class)->add($other->id, 'foreign.example');

    $this->post(route('connections.domains.verify', $foreign->id))->assertForbidden();
    $this->post(route('connections.domains.capture', $foreign->id))->assertForbidden();
    $this->delete(route('connections.domains.destroy', $foreign->id))->assertForbidden();

    expect(VerifiedDomain::query()->whereKey($foreign->id)->exists())->toBeTrue();
})->group('security');

it('keeps the whole-environment connection overview on the environment plane', function (): void {
    // With no organization chosen the list is every connection in the environment. That
    // is the environment administrator's overview, and it is only reachable on this
    // plane — an organization member's organization is implicit, so they can never land
    // in this branch.
    $orgId = anEnvironmentAdminActingOn('tenant-sso-overview');
    $other = app(Organizations::class)->create(new NewOrganization('Second Co', 'second-sso'));
    aSamlConnection($orgId, 'First IdP');
    aSamlConnection($other->id, 'Second IdP');

    session()->forget(ConsoleScope::SELECTION_KEY);

    $this->get(route('environment.connections'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connections', fn (Collection $rows): bool => $rows
                ->pluck('name')
                ->contains('First IdP') && $rows->pluck('name')->contains('Second IdP'))
            // Domain verification and the portal invite belong to ONE organization, so
            // they wait for a choice rather than acting on whichever the page loaded.
            ->where('needsOrganization', true)
            ->where('domains', []));
})->group('security');

it('refuses single sign-on to an organization admin with no organization at all', function (): void {
    // The nullable reader answers null both for "an environment administrator has not
    // chosen an organization yet" and for "this member has none", and the first means
    // "show the whole environment". Told apart here, because conflating them turns an
    // organization page into a list of every connection in the environment.
    $subject = app(Subjects::class)->create('orphan-sso@acme.test', 'Orphan', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-orphan-sso'));
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, null, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $this->get(route('connections'))->assertForbidden();
})->group('security');

/*
|--------------------------------------------------------------------------
| Appearance
|--------------------------------------------------------------------------
| The nearest to true parity of the four, and still not the same thing underneath: the
| organization page themed an ORGANIZATION, the environment page themed the ENVIRONMENT
| default that every organization inherits. That difference is a capability, so it is an
| explicit choice on the merged page rather than an implication of the door you came
| through — and it stays on the environment plane alone. The organization page's contrast
| gate, which the environment page never had, now guards both.
*/

it('serves appearance from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-appearance');

    $this->get(route('environment.appearance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/appearance')
            // The plane that holds the environment, and the only one offered the choice.
            ->where('mayThemeEnvironment', true));
})->group('security');

it('serves appearance from the same component on the organization plane', function (): void {
    actingAsRole(MembershipRole::Owner);

    $this->get(route('appearance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/appearance')
            ->where('mayThemeEnvironment', false));
})->group('security');

/**
 * Press Save in the theme editor, on the named plane.
 *
 * The logo travels BESIDE the theme, because it is not part of the typed appearance: the
 * colours, radius and font are sanitized by `Appearance::fromArray()`, and a URL this
 * server will render into an `<img src>` on an unauthenticated page is a different kind of
 * value with a different rule.
 *
 * @param  array<string, mixed>  $theme
 */
function saveAppearance(string $plane, array $theme, bool $environmentDefault = false): TestResponse
{
    unset($theme['logo'], $theme['name']);

    return test()->from(route($plane))->post(route($plane.'.update'), [
        'theme' => $theme,
        'environmentDefault' => $environmentDefault,
    ]);
}

it('still themes the environment default when the environment console saves', function (): void {
    // The landing state on this plane, unchanged by the merge. Retargeting it at whichever
    // organization happened to be picked would have an operator re-theming one tenant
    // while believing they were setting the default for all of them.
    $orgId = anEnvironmentAdminActingOn('tenant-appearance-env');
    $environmentId = (string) app(EnvironmentContext::class)->current()?->environmentKey();

    $theme = Appearance::fromPreset('midnight')->toArray();
    $theme['light']['primary'] = '#00aa88';

    saveAppearance('environment.appearance', $theme, environmentDefault: true)
        ->assertRedirect(route('environment.appearance'))
        ->assertSessionHasNoErrors();

    expect(Environment::query()->find($environmentId)?->settings['appearance']['light']['primary'])->toBe('#00aa88')
        ->and(app(Organizations::class)->find($orgId)?->settings['appearance'] ?? null)->toBeNull();
})->group('security');

it('lets the environment console theme the organization it is acting on instead', function (): void {
    // The other half of the choice — and the capability the organization plane always had,
    // now reachable from this one without switching consoles.
    $orgId = anEnvironmentAdminActingOn('tenant-appearance-org');
    $environmentId = (string) app(EnvironmentContext::class)->current()?->environmentKey();

    $theme = Appearance::fromPreset('warm')->toArray();
    $theme['light']['primary'] = '#123456';

    saveAppearance('environment.appearance', $theme)
        ->assertRedirect(route('environment.appearance'))
        ->assertSessionHasNoErrors();

    expect(app(Organizations::class)->find($orgId)?->settings['appearance']['light']['primary'])->toBe('#123456')
        ->and(Environment::query()->find($environmentId)?->settings['appearance'] ?? null)->toBeNull();
})->group('security');

it('refuses to theme an organization before one is chosen', function (): void {
    // The editor is not even drawn in this state, so this is the forged half: with no
    // organization resolved the write would otherwise land wherever a downstream default
    // pointed, which on this plane is somebody's tenant.
    $orgId = anEnvironmentAdminActingOn('tenant-appearance-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    saveAppearance('environment.appearance', Appearance::fromPreset('warm')->toArray())
        ->assertForbidden();

    expect(app(Organizations::class)->find($orgId)?->settings['appearance'] ?? null)->toBeNull();
})->group('security');

it('refuses an organization admin the environment default theme', function (): void {
    // Forged, not clicked: the radio is not rendered on this plane, so the refusal has to
    // live in save(). An organization admin who reached it would be re-theming the sign-in
    // page of every other tenant in the environment.
    actingAsRole(MembershipRole::Owner);
    $environmentId = (string) app(EnvironmentContext::class)->current()?->environmentKey();

    $theme = Appearance::fromPreset('midnight')->toArray();
    $theme['light']['primary'] = '#00aa88';

    saveAppearance('appearance', $theme, environmentDefault: true)->assertForbidden();

    expect(Environment::query()->find($environmentId)?->settings['appearance'] ?? null)->toBeNull();
})->group('security');

it('refuses an unreadable environment default, which only the organization plane refused before', function (): void {
    // The gate the environment page never had. An operator could set an environment
    // default no tenant's users could read, and the people who cannot read the sign-in
    // page are never the admin who chose the colours.
    anEnvironmentAdminActingOn('tenant-appearance-contrast');
    $environmentId = (string) app(EnvironmentContext::class)->current()?->environmentKey();

    saveAppearance('environment.appearance', [
        'radius' => '0.5rem',
        'font' => 'system',
        'light' => ['primary' => '#3b6fd4', 'background' => '#101014', 'foreground' => '#141418', 'muted' => '#16161a'],
        'dark' => ['primary' => '#3b6fd4', 'background' => '#1e1e21', 'foreground' => '#f5f5f5', 'muted' => '#a0a0a0'],
    ], environmentDefault: true)->assertSessionHasErrors('theme');

    expect(Environment::query()->find($environmentId)?->settings['appearance'] ?? null)
        ->toBeNull('an unreadable environment default was saved');
})->group('security');

it('does not offer the environment default to an organization admin', function (): void {
    // The view half. A merge that rewires only the PHP leaves the control on screen for
    // someone the server will refuse.
    actingAsRole(MembershipRole::Owner);

    $this->get(route('appearance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('mayThemeEnvironment', false));
})->group('security');

it('does offer it to the administrator who holds the environment', function (): void {
    // The other half of the same trap, and the one that renders a read-only shell: a
    // capability enforced correctly but never drawn is a capability nobody has.
    //
    // A separate test rather than the second half of the one above: the two planes are
    // told apart by which session exists, and a subject session from the organization
    // half would still be standing when the environment half ran.
    anEnvironmentAdminActingOn('tenant-appearance-view');

    $this->get(route('environment.appearance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('mayThemeEnvironment', true));
})->group('security');

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
| The pair that looked least like one: the organization page was the ORGANIZATION's
| record — rename, slug, type, id — and the environment page was the ENVIRONMENT's
| identity plus the OIDC issuer and discovery URL. Neither offered what the other did.
|
| They are one capability under the console's own rule — the environment administrator
| sees what a tenant admin sees, plus what the environment holds in addition. So the
| organization's record is on both planes, the environment's identity on the environment
| plane alone, and the integration details on both.
*/

it('serves settings from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-settings');

    $this->get(route('environment.settings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/settings')
            // The integration block, and the environment's own identity beside it.
            ->where('discovery', fn (string $url): bool => str_ends_with($url, '/.well-known/openid-configuration'))
            ->whereNot('environmentRecord', null));
})->group('security');

it('serves settings from the same component on the organization plane', function (): void {
    actingAsRole(MembershipRole::Owner);

    $this->get(route('settings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/settings')
            ->whereNot('organization', null));
})->group('security');

it('gives the environment plane the rename it never had', function (): void {
    // An administrator who holds every organization in the environment could not correct
    // a typo in one's name without signing into that organization's own console.
    $orgId = anEnvironmentAdminActingOn('tenant-settings-rename');

    $this->patch(route('environment.settings.rename'), ['name' => 'Renamed Co'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(Organizations::class)->find($orgId)?->name)->toBe('Renamed Co');
})->group('security');

it('attributes the rename to the subject who made it, on either plane', function (): void {
    // The two consoles recorded ids from different tables for the same act — the
    // organization plane the subject id, the environment plane the Membership row's.
    // Half the trail resolved against `users` and half against `account_members`, with
    // nothing recording which.
    $orgId = anEnvironmentAdminActingOn('tenant-settings-actor');
    $subjectId = app(EnvironmentAdminAuth::class)->subjectId();

    $this->patch(route('environment.settings.rename'), ['name' => 'Attributed Co']);

    expect(AuditEntry::query()->where('action', 'organization.renamed')->value('actor_id'))
        ->toBe($subjectId)
        ->and(AuditEntry::query()->where('action', 'organization.renamed')->value('organization_id'))
        ->toBe($orgId);
})->group('security');

it('refuses a rename before an organization is chosen', function (): void {
    anEnvironmentAdminActingOn('tenant-settings-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    $this->patch(route('environment.settings.rename'), ['name' => 'Nobody In Particular'])
        ->assertForbidden();

    expect(AuditEntry::query()->where('action', 'organization.renamed')->exists())->toBeFalse();
})->group('security');

it('renames the organization the console is acting on and no other', function (): void {
    // The rename takes no id from the wire — it resolves the target through the scope —
    // so the other organization in the environment is untouchable from this page.
    $orgId = anEnvironmentAdminActingOn('tenant-settings-scoped');
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-settings'));

    $this->patch(route('environment.settings.rename'), ['name' => 'Only Mine']);

    expect(app(Organizations::class)->find($orgId)?->name)->toBe('Only Mine')
        ->and(app(Organizations::class)->find($other->id)?->name)->toBe('Other Co');
})->group('security');

it('gives the organization plane the integration details it never had', function (): void {
    // The environment page's half. An organization admin wiring an OIDC client needed
    // exactly these two URLs and had nowhere in their own console to find them; both are
    // already served unauthenticated, so this publishes nothing new.
    actingAsRole(MembershipRole::Owner);

    $this->get(route('settings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('discovery', fn (string $url): bool => str_ends_with($url, '/.well-known/openid-configuration'))
            ->where('issuer', fn (string $url): bool => $url !== '' && ! str_ends_with($url, '/')));
})->group('security');

it('keeps the environment\'s own identity off the organization plane', function (): void {
    // The other direction of the same merge. The environment record is the control
    // plane's; a tenant admin has no business reading it and no way to act on it.
    //
    // The environment is made REAL and current first. The suite's default context names
    // an environment key with no row behind it, so a page that resolved the record
    // unconditionally would still render nothing here — and this assertion would pass
    // while guarding nothing.
    $environment = platformRootEnvironment();
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));

    actingAsRole(MembershipRole::Owner);

    $this->get(route('settings'))
        ->assertOk()
        // The prop itself, not the rendered page: under Inertia the props ARE the page,
        // and an absent block is the only thing that keeps the record off this plane.
        ->assertInertia(fn (AssertableInertia $page) => $page->where('environmentRecord', null))
        // Its ID, not its NAME. The shell names the realm on every page in it — that is
        // the sandbox badge's whole job — so asserting the name is absent would be
        // asserting the chrome is missing. The id is the control plane's own identifier
        // and belongs to no tenant's page.
        ->assertDontSee($environment->id);
})->group('security');

it('serves social sign-in on the environment plane, not only the organization one', function (): void {
    // This capability shipped reachable from one console only — and not the one its owner
    // uses. The owner holds an ordinary subject session, so
    // reaching their own feature meant impersonating one of their own users.
    $orgId = anEnvironmentAdminActingOn('tenant-social');

    $this->get(route('environment.social-providers'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/social-providers')
            // The catalogue is offered here, which is the claim: the same page, on the
            // plane whose owner could not reach it.
            ->where('available', fn (Collection $rows): bool => $rows->pluck('name')->contains('GitHub'))
            ->where('available', fn (Collection $rows): bool => $rows->pluck('name')->contains('Apple'))
            // …and its writes point at THIS plane's routes, which is the half a shared
            // component gets wrong: a form posting to the other plane's URL.
            ->where('storeHref', route('environment.social-providers.store')));

    expect($orgId)->not->toBe('');
})->group('security');

it('refuses to enable a provider before an organization is chosen', function (): void {
    anEnvironmentAdminActingOn('tenant-social-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    // The READ renders — the page has to, or the acting-organization picker in the console
    // header is unreachable and the administrator can never choose one. The WRITE is what
    // must refuse, and it does so by demanding an organization rather than by silently
    // writing to none.
    $this->get(route('environment.social-providers'))->assertOk();

    test()->from(route('environment.social-providers'))
        ->post(route('environment.social-providers.store'), [
            'provider' => 'github',
            'clientId' => 'gh',
            'clientSecret' => 'gh',
            'parameters' => [],
        ])
        ->assertForbidden();
})->group('security');

/*
|--------------------------------------------------------------------------
| Sign-in rules
|--------------------------------------------------------------------------
| Not a merge of two pages: the environment plane had one and the organization plane
| had none, while both sign-in doors enforced the per-organization policy on every
| attempt. So the merged component gives the organization plane a capability it never
| had — its own override, and a way to give it back — and the environment plane keeps
| the baseline and the per-organization table it always had.
*/

it('serves sign-in rules from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-rules');

    $this->get(route('environment.auth-policy'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/auth-policy')
            ->where('title', 'Sign-in rules')
            // The environment's half: the baseline, and what each organization ends up
            // with. The table is a PROP, so its presence is the fact rather than a word
            // in a document.
            ->where('onEnvironmentPlane', true)
            ->whereNot('organizations', null));
})->group('security');

it('serves sign-in rules from the same component on the organization plane', function (): void {
    actingAsRole(MembershipRole::Owner);

    $this->get(route('auth-policy'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/auth-policy')
            ->where('title', 'Sign-in rules')
            // …and the organization's half is the override, not the environment's
            // baseline table: a tenant administrator has no business reading every other
            // tenant's policy, so the rows are not withheld from the markup — they are
            // never fetched.
            ->where('onEnvironmentPlane', false)
            ->where('organizations', null));

    expect(Route::has('auth-policy'))->toBeTrue();
})->group('security');

/*
 * A TENANT'S SIEM RECEIVES THAT TENANT'S EVENTS, NOT THE ENVIRONMENT'S.
 *
 * Log streaming moved to the organization plane on the fair argument that shipping an
 * audit trail to a SIEM is a compliance obligation the organization carries. The stream
 * it created was environment-owned, as every stream had been while only an operator could
 * make one — so an administrator of organization A registered their own endpoint and
 * started receiving B and C's sign-ins, role changes and member events. Not a leak anyone
 * had to work for: the feature working as built, on a plane that was never in its design.
 */
it('stamps a stream created on the organization plane with that organization', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    confirmConsoleStepUp();
    createLogStream()->assertSessionHasNoErrors();

    expect(AuditStream::query()->value('organization_id'))->toBe($org->id);
})->group('security');

it('keeps the environment-wide stream on the environment plane, where the operator is', function (): void {
    anEnvironmentAdminActingOn('tenant-streams-wide');

    confirmConsoleStepUp();
    createLogStream([
        'name' => 'Operator Splunk',
        'endpointUrl' => 'https://siem.operator.example/collector',
    ], 'environment.audit-streams')->assertSessionHasNoErrors();

    // Null is the environment's own: it receives everything, which is what makes it the
    // operator's compliance shipping and not a tenant's.
    expect(AuditStream::query()->whereNull('organization_id')->exists())->toBeTrue();
})->group('security');

it('shows an organization only the streams it owns, never the environment’s', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    // The tenant's own, made the way the console makes it.
    confirmConsoleStepUp();
    createLogStream()->assertSessionHasNoErrors();

    // And the environment's own, which only the operator can make — written directly
    // because the organization plane has no way to express it, which is the point.
    $operators = app(LogStreams::class)->create(
        'Operator',
        SiemDestination::GenericJson,
        'https://siem.operator.example/collector',
        null,
        SiemAuthScheme::None,
    );

    /*
     * OWNED, not delivered. The organization is DELIVERED the environment's own stream's
     * attention and must never manage it — a list built from the delivery relation would
     * show a tenant the operator's SIEM endpoint with a pause button beside it.
     */
    test()->get(route('audit-streams'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'streams',
            fn (Collection $streams): bool => $streams->pluck('name')->all() === ['Acme Splunk']
                && $streams->pluck('id')->doesntContain($operators->stream->id),
        ));

    expect(AuditStream::query()->where('name', 'Acme Splunk')->value('organization_id'))->toBe($org->id);
})->group('security');

it('404s a stream belonging to the environment when a tenant asks for it by id', function (): void {
    actingAsRole(MembershipRole::Owner);

    $operators = app(LogStreams::class)->create(
        'Operator',
        SiemDestination::GenericJson,
        'https://siem.operator.example/collector',
        null,
        SiemAuthScheme::None,
    );

    // The id is not a secret — it is in the operator's own URL bar — so guessing it must
    // not be the control. Ownership is.
    test()->get(route('audit-streams.show', $operators->stream->id))->assertNotFound();

    // And every mutation resolves the id inside the same ownership fence.
    test()->post(route('audit-streams.toggle', $operators->stream->id))->assertNotFound();
    test()->delete(route('audit-streams.destroy', $operators->stream->id))->assertNotFound();

    expect(AuditStream::query()->whereKey($operators->stream->id)->value('enabled'))->toBeTrue();
})->group('security');
