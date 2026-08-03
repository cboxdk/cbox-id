<?php

declare(strict_types=1);

use App\Platform\Appearance\Appearance;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\ActionEndpointStatus;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Cbox\Id\Provisioning\Enums\ConnectionStatus;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
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
    platformRootEnvironment();

    $provisioned = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'parity-'.$slug.'@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->member, $provisioned->environment->id);

    $orgId = app(Organizations::class)->create(new NewOrganization('Tenant Co', $slug))->id;
    app(ConsoleScope::class)->chooseOrganization($orgId);

    return $orgId;
}

it('serves access reviews from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn();

    $this->get(route('environment.governance'))->assertOk()->assertSee('Access reviews');
    $this->get(route('environment.governance.create'))->assertOk();
})->group('security');

it('serves access reviews from the same component on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: a campaign URL is something you send to a reviewer.
    actingAsRole(MembershipRole::Owner);

    // Driven at the component, because actingAsRole() populates CurrentUser the way the
    // middleware would rather than minting a session cookie — an HTTP request would just
    // bounce to sign-in and prove nothing about the page.
    Volt::test('console.governance.index')->assertOk()->assertSee('Access reviews');
    Volt::test('console.governance.create')->assertOk();

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

    Volt::test('console.governance.create')
        ->set('name', 'Q3 review')
        ->call('open')
        ->assertHasNoErrors();

    expect(CertificationCampaign::query()->where('organization_id', $orgId)->exists())->toBeTrue();
})->group('security');

it('refuses to open a review before an organization is chosen', function (): void {
    anEnvironmentAdminActingOn('tenant-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    Volt::test('console.governance.create')
        ->set('name', 'Q3 review')
        ->call('open')
        ->assertHasErrors('name');
})->group('security');

/*
|--------------------------------------------------------------------------
| Sync users out (outbound SCIM provisioning)
|--------------------------------------------------------------------------
| The organization plane had one page: an inline register form and pause. The
| environment plane had list → create → detail with pause, resume and delete, plus an
| "All organizations" option on its form. The merge is the union of all of it.
*/

it('serves outbound sync from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-sync');

    $this->get(route('environment.provisioning'))->assertOk()->assertSee('Sync users out');
    $this->get(route('environment.provisioning.create'))->assertOk();
})->group('security');

it('serves outbound sync from the same component on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: a connection URL is something you send to whoever runs the app it
    // provisions.
    actingAsRole(MembershipRole::Owner);

    // Driven at the component, because actingAsRole() populates CurrentUser the way the
    // middleware would rather than minting a session cookie — an HTTP request would just
    // bounce to sign-in and prove nothing about the page.
    Volt::test('console.provisioning.index')->assertOk()->assertSee('Sync users out');
    Volt::test('console.provisioning.create')->assertOk();

    expect(Route::has('provisioning.show'))->toBeTrue()
        ->and(Route::has('provisioning.create'))->toBeTrue();
})->group('security');

it('registers an outbound connection against the organization from the scope', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    $orgId = anEnvironmentAdminActingOn('tenant-sync-scoped');

    Volt::test('console.provisioning.create')
        ->set('name', 'Downstream')
        ->set('baseUrl', 'https://scim.example.test/v2')
        ->set('scheme', 'bearer')
        ->set('secret', 'tok_123')
        ->call('create')
        ->assertHasNoErrors();

    expect(ProvisioningConnection::query()->where('organization_id', $orgId)->exists())->toBeTrue();
})->group('security');

it('refuses to register an outbound connection before an organization is chosen', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-sync-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    Volt::test('console.provisioning.create')
        ->set('name', 'Downstream')
        ->set('baseUrl', 'https://scim.example.test/v2')
        ->set('scheme', 'bearer')
        ->set('secret', 'tok_123')
        ->call('create')
        ->assertHasErrors('name');

    expect(ProvisioningConnection::query()->exists())->toBeFalse();
})->group('security');

it('keeps environment-wide registration on the environment plane', function (): void {
    // What the removed organization picker also carried: its "All organizations" option
    // was never an organization, it was environment-wide coverage — a platform
    // capability, so it survives as its own explicit choice on this plane alone.
    config(['cbox-id.provisioning.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-sync-wide');

    Volt::test('console.provisioning.create')
        ->set('name', 'Everything')
        ->set('baseUrl', 'https://scim.example.test/v2')
        ->set('scheme', 'bearer')
        ->set('secret', 'tok_123')
        ->set('environmentWide', true)
        ->call('create')
        ->assertHasNoErrors();

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

    Volt::test('console.provisioning.show', ['sync' => $connection->id])
        ->call('pause')
        ->assertHasNoErrors();
    expect($connection->fresh()->status)->toBe(ConnectionStatus::Paused);

    Volt::test('console.provisioning.show', ['sync' => $connection->id])
        ->call('resume')
        ->assertHasNoErrors();
    expect($connection->fresh()->status)->toBe(ConnectionStatus::Active);

    Volt::test('console.provisioning.show', ['sync' => $connection->id])->call('deleteConnection');
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

it('serves inline hooks from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-hooks');

    $this->get(route('environment.hooks'))->assertOk()->assertSee('Inline hooks');
    $this->get(route('environment.hooks.create'))->assertOk();
})->group('security');

it('serves inline hooks from the same component on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: the reveal-once signing secret needs somewhere to land that is not the
    // row you just submitted.
    actingAsRole(MembershipRole::Owner);

    // Driven at the component, because actingAsRole() populates CurrentUser the way the
    // middleware would rather than minting a session cookie — an HTTP request would just
    // bounce to sign-in and prove nothing about the page.
    Volt::test('console.hooks.index')->assertOk()->assertSee('Inline hooks');
    Volt::test('console.hooks.create')->assertOk();

    expect(Route::has('hooks.show'))->toBeTrue()
        ->and(Route::has('hooks.create'))->toBeTrue();
})->group('security');

it('offers every hook point on both planes', function (): void {
    // The drift that mattered most here. The organization console hard-coded a single
    // <option>, so a tenant could enrich a token and could NOT refuse a sign-in, a
    // sign-up or a password change — four of the six points the enum publishes, missing
    // from one console and present in the other, with nothing saying so.
    $expected = array_map(fn (HookPoint $point): string => $point->label(), HookPoint::cases());

    anEnvironmentAdminActingOn('tenant-hookpoints');
    $environment = Volt::test('console.hooks.create');

    actingAsRole(MembershipRole::Owner);
    $organization = Volt::test('console.hooks.create');

    foreach ($expected as $label) {
        $environment->assertSee($label);
        $organization->assertSee($label);
    }
})->group('security');

it('registers a hook against the organization from the scope', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = anEnvironmentAdminActingOn('tenant-hooks-scoped');

    Volt::test('console.hooks.create')
        ->set('url', 'https://hooks.example.test/token')
        ->call('register')
        ->assertHasNoErrors();

    expect(ExternalActionEndpoint::query()->where('organization_id', $orgId)->exists())->toBeTrue();
})->group('security');

it('refuses to register a hook before an organization is chosen', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-hooks-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    Volt::test('console.hooks.create')
        ->set('url', 'https://hooks.example.test/token')
        ->call('register')
        ->assertHasErrors('url');

    expect(ExternalActionEndpoint::query()->exists())->toBeFalse();
})->group('security');

it('keeps environment-wide hook registration on the environment plane', function (): void {
    // What the removed organization picker also carried: its "All organizations" option
    // was never an organization, it was an endpoint the environment itself owns.
    config(['cbox-id.external_actions.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-hooks-wide');

    Volt::test('console.hooks.create')
        ->set('url', 'https://hooks.example.test/everyone')
        ->set('environmentWide', true)
        ->call('register')
        ->assertHasNoErrors();

    expect(ExternalActionEndpoint::query()->whereNull('organization_id')->exists())->toBeTrue();
})->group('security');

it('refuses environment-wide registration from the organization plane', function (): void {
    // At most of these points a hook can REFUSE the operation, so an environment-wide
    // endpoint minted by a tenant could stop every other tenant signing in. The checkbox
    // is not rendered for them; the property is still client-settable, so the refusal is
    // in the action.
    config(['cbox-id.external_actions.verify_url' => false]);
    actingAsRole(MembershipRole::Owner);

    Volt::test('console.hooks.create')
        ->set('url', 'https://hooks.example.test/everyone')
        ->set('environmentWide', true)
        ->call('register')
        ->assertForbidden();

    expect(ExternalActionEndpoint::query()->exists())->toBeFalse();
})->group('security');

it('runs the whole hook lifecycle on the environment plane', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = anEnvironmentAdminActingOn('tenant-hooks-lifecycle');

    $endpoint = app(ExternalActions::class)
        ->register(HookPoint::TokenMinting, 'https://hooks.example.test/token', $orgId)->endpoint;

    Volt::test('console.hooks.show', ['hook' => $endpoint->id])->call('pause')->assertHasNoErrors();
    expect($endpoint->fresh()?->status)->toBe(ActionEndpointStatus::Paused);

    Volt::test('console.hooks.show', ['hook' => $endpoint->id])->call('activate')->assertHasNoErrors();
    expect($endpoint->fresh()?->status)->toBe(ActionEndpointStatus::Active);

    Volt::test('console.hooks.show', ['hook' => $endpoint->id])->call('remove')->assertHasNoErrors();
    expect(ExternalActionEndpoint::query()->whereKey($endpoint->id)->exists())->toBeFalse();
})->group('security');

it('offers dismissing the revealed secret on the environment plane too', function (): void {
    // Only the organization console had this. Dropping it in the merge would have left a
    // live credential on screen with no way to clear it — on the plane whose
    // administrator holds every organization in the environment.
    config(['cbox-id.external_actions.verify_url' => false]);
    anEnvironmentAdminActingOn('tenant-hooks-secret');

    Volt::test('console.hooks.create')
        ->set('url', 'https://hooks.example.test/token')
        ->call('register')
        ->assertHasNoErrors();

    $endpoint = ExternalActionEndpoint::query()->firstOrFail();

    Volt::test('console.hooks.show', ['hook' => $endpoint->id])
        ->assertSee('Copy this signing secret now')
        ->call('dismissSecret')
        ->assertDontSee('Copy this signing secret now');
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

it('serves role conflicts from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-sod');

    $this->get(route('environment.sod-policies'))->assertOk()->assertSee('Role conflicts');
    $this->get(route('environment.sod-policies.create'))->assertOk();
})->group('security');

it('serves role conflicts from the same component on the organization plane', function (): void {
    // The organization plane gained the routable shape rather than the environment plane
    // losing it: a rule URL is something you send to whoever owns the control.
    actingAsRole(MembershipRole::Owner);

    // Driven at the component, because actingAsRole() populates CurrentUser the way the
    // middleware would rather than minting a session cookie — an HTTP request would just
    // bounce to sign-in and prove nothing about the page.
    Volt::test('console.sod-policies.index')->assertOk()->assertSee('Role conflicts');
    Volt::test('console.sod-policies.create')->assertOk();

    expect(Route::has('sod-policies.show'))->toBeTrue()
        ->and(Route::has('sod-policies.create'))->toBeTrue();
})->group('security');

it('defines a rule against the organization from the scope', function (): void {
    $orgId = anEnvironmentAdminActingOn('tenant-sod-scoped');
    $a = app(Roles::class)->define($orgId, 'create-po');
    $b = app(Roles::class)->define($orgId, 'approve-pay');

    Volt::test('console.sod-policies.create')
        ->set('name', 'PO vs pay')
        ->set('roleIds', [$a->id, $b->id])
        ->call('define')
        ->assertHasNoErrors();

    expect(SodPolicy::query()->where('organization_id', $orgId)->exists())->toBeTrue();
})->group('security');

it('refuses to define a rule before an organization is chosen', function (): void {
    $orgId = anEnvironmentAdminActingOn('tenant-sod-unchosen');
    $a = app(Roles::class)->define($orgId, 'create-po');
    $b = app(Roles::class)->define($orgId, 'approve-pay');
    session()->forget(ConsoleScope::SELECTION_KEY);

    Volt::test('console.sod-policies.create')
        ->set('name', 'PO vs pay')
        ->set('roleIds', [$a->id, $b->id])
        ->call('define')
        ->assertHasErrors('name');

    expect(SodPolicy::query()->exists())->toBeFalse();
})->group('security');

it('keeps environment-wide rules on the environment plane', function (): void {
    // What the removed organization picker also carried: its "All organizations" option
    // was never an organization, it was a rule that binds every organization here — so
    // it survives as its own explicit choice on this plane alone.
    $orgId = anEnvironmentAdminActingOn('tenant-sod-wide');
    $a = app(Roles::class)->define($orgId, 'create-po');
    $b = app(Roles::class)->define($orgId, 'approve-pay');

    Volt::test('console.sod-policies.create')
        ->set('name', 'Everywhere')
        ->set('roleIds', [$a->id, $b->id])
        ->set('environmentWide', true)
        ->call('define')
        ->assertHasNoErrors();

    expect(SodPolicy::query()->whereNull('organization_id')->exists())->toBeTrue();
})->group('security');

it('refuses an organization admin a rule that binds every organization', function (): void {
    // Forged, not clicked: the checkbox is not rendered on this plane, so the refusal has
    // to live in define() — an organization admin writing an environment-wide rule would
    // be legislating for organizations that are not theirs.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $a = app(Roles::class)->define($org->id, 'create-po');
    $b = app(Roles::class)->define($org->id, 'approve-pay');

    Volt::test('console.sod-policies.create')
        ->set('name', 'Everywhere')
        ->set('roleIds', [$a->id, $b->id])
        ->set('environmentWide', true)
        ->call('define')
        ->assertHasErrors('environmentWide');

    expect(SodPolicy::query()->exists())->toBeFalse();
})->group('security');

it('gives the organization plane the evaluate and delete it never had', function (): void {
    // Both actions existed only on the environment plane. An organization's own rule is
    // its own to evaluate and to remove — the environment-wide one still is not.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $a = app(Roles::class)->define($org->id, 'create-po');
    $b = app(Roles::class)->define($org->id, 'approve-pay');
    $policy = app(SegregationOfDuties::class)->definePolicy($org->id, 'PO vs pay', [$a->id, $b->id]);

    Volt::test('console.sod-policies.show', ['policy' => $policy->id])
        ->call('scan')
        ->assertHasNoErrors()
        ->call('remove');

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

    Volt::test('console.sod-policies.show', ['policy' => $policy->id])
        ->assertSee('Env-wide PO vs pay')
        ->assertSee('Managed for the environment')
        ->assertDontSee('Delete rule');

    // And forged, not merely unrendered.
    expect(fn () => Volt::test('console.sod-policies.show', ['policy' => $policy->id])->call('remove'))
        ->toThrow(ModelNotFoundException::class);

    expect(SodPolicy::query()->whereKey($policy->id)->exists())->toBeTrue();
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

    Volt::test('console.sod-policies.index')->assertForbidden();
})->group('security');

it('refuses an organization admin another organization\'s rule', function (): void {
    // The environment plane resolved a rule anywhere in the environment, which is right
    // for an administrator who holds it. Serving the same component to an organization
    // admin would have handed them every other organization's rules by id.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-sod'));
    $role = app(Roles::class)->define($other->id, 'create-po');
    $second = app(Roles::class)->define($other->id, 'approve-pay');
    $policy = app(SegregationOfDuties::class)->definePolicy($other->id, 'Not yours', [$role->id, $second->id]);

    expect(fn () => Volt::test('console.sod-policies.show', ['policy' => $policy->id]))
        ->toThrow(ModelNotFoundException::class);

    Volt::test('console.sod-policies.index')->assertDontSee('Not yours');

    expect(fn () => Volt::test('console.sod-policies.index')->call('toggle', $policy->id))
        ->toThrow(ModelNotFoundException::class);
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
function auditActions(mixed $component): array
{
    return collect($component->viewData('entries')->items())->pluck('action')->all();
}

it('serves the activity log from one component on the environment plane', function (): void {
    anEnvironmentAdminActingOn('tenant-audit');

    $this->get(route('environment.audit'))->assertOk()->assertSee('Activity log');
})->group('security');

it('serves the activity log from the same component on the organization plane', function (): void {
    actingAsRole(MembershipRole::Owner);

    Volt::test('console.audit')->assertOk()->assertSee('Activity log');
})->group('security');

it('scopes the trail to the organization the environment console is acting on', function (): void {
    // The picker is not decoration: an environment administrator reading one tenant's
    // trail must be reading THAT tenant's, not a merged feed of every tenant's.
    $orgId = anEnvironmentAdminActingOn('tenant-audit-scoped');
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-audit'))->id;

    anAuditEntry('mine.recorded', $orgId);
    anAuditEntry('theirs.recorded', $other);

    expect(auditActions(Volt::test('console.audit')))
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
    expect(auditActions(Volt::test('console.audit')))
        ->toContain('mine.recorded')
        ->not->toContain('theirs.recorded')
        ->not->toContain('environment.recorded');

    // And the search box is not an enumeration oracle around the scope.
    expect(auditActions(Volt::test('console.audit')->set('search', 'theirs.recorded')))->toBe([]);
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

    expect(auditActions(Volt::test('console.audit')))
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

    anAuditEntry('environment.recorded');

    Volt::test('console.audit')->assertForbidden();
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
    expect(auditActions(Volt::test('console.audit')->set('actionFilter', 'client')))
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

    expect(auditActions(Volt::test('console.audit')->set('search', 'directory')))
        ->toBe(['member.added']);
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

    $this->get(route('environment.appearance'))->assertOk()->assertSee('Live preview');
})->group('security');

it('serves appearance from the same component on the organization plane', function (): void {
    actingAsRole(MembershipRole::Owner);

    Volt::test('console.appearance')->assertOk()->assertSee('Live preview');
})->group('security');

it('still themes the environment default when the environment console saves', function (): void {
    // The landing state on this plane, unchanged by the merge. Retargeting it at whichever
    // organization happened to be picked would have an operator re-theming one tenant
    // while believing they were setting the default for all of them.
    $orgId = anEnvironmentAdminActingOn('tenant-appearance-env');
    $environmentId = (string) app(EnvironmentContext::class)->current()?->environmentKey();

    $theme = Appearance::fromPreset('midnight')->toArray();
    $theme['light']['primary'] = '#00aa88';

    Volt::test('console.appearance')->call('save', $theme);

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

    Volt::test('console.appearance')
        ->set('environmentDefault', false)
        ->call('save', $theme);

    expect(app(Organizations::class)->find($orgId)?->settings['appearance']['light']['primary'])->toBe('#123456')
        ->and(Environment::query()->find($environmentId)?->settings['appearance'] ?? null)->toBeNull();
})->group('security');

it('refuses to theme an organization before one is chosen', function (): void {
    // The editor is not even drawn in this state, so this is the forged half: with no
    // organization resolved the write would otherwise land wherever a downstream default
    // pointed, which on this plane is somebody's tenant.
    $orgId = anEnvironmentAdminActingOn('tenant-appearance-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    Volt::test('console.appearance')
        ->set('environmentDefault', false)
        ->call('save', Appearance::fromPreset('warm')->toArray())
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

    Volt::test('console.appearance')
        ->set('environmentDefault', true)
        ->call('save', $theme)
        ->assertForbidden();

    expect(Environment::query()->find($environmentId)?->settings['appearance'] ?? null)->toBeNull();
})->group('security');

it('refuses an unreadable environment default, which only the organization plane refused before', function (): void {
    // The gate the environment page never had. An operator could set an environment
    // default no tenant's users could read, and the people who cannot read the sign-in
    // page are never the admin who chose the colours.
    anEnvironmentAdminActingOn('tenant-appearance-contrast');
    $environmentId = (string) app(EnvironmentContext::class)->current()?->environmentKey();

    Volt::test('console.appearance')->call('save', [
        'radius' => '0.5rem',
        'font' => 'system',
        'light' => ['primary' => '#3b6fd4', 'background' => '#101014', 'foreground' => '#141418', 'muted' => '#16161a'],
        'dark' => ['primary' => '#3b6fd4', 'background' => '#1e1e21', 'foreground' => '#f5f5f5', 'muted' => '#a0a0a0'],
    ]);

    expect(Environment::query()->find($environmentId)?->settings['appearance'] ?? null)
        ->toBeNull('an unreadable environment default was saved');
})->group('security');

it('does not offer the environment default to an organization admin', function (): void {
    // The view half. A merge that rewires only the PHP leaves the control on screen for
    // someone the server will refuse.
    actingAsRole(MembershipRole::Owner);

    Volt::test('console.appearance')->assertDontSee('Environment default');
})->group('security');

it('does offer it to the administrator who holds the environment', function (): void {
    // The other half of the same trap, and the one that renders a read-only shell: a
    // capability enforced correctly but never drawn is a capability nobody has.
    //
    // A separate test rather than the second half of the one above: the two planes are
    // told apart by which session exists, and a subject session from the organization
    // half would still be standing when the environment half ran.
    anEnvironmentAdminActingOn('tenant-appearance-view');

    Volt::test('console.appearance')->assertSee('Environment default');
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

    // Driven at the component, because actingAsRole() populates CurrentUser the way the
    // middleware would rather than minting a session cookie — an HTTP request would just
    // bounce to sign-in and prove nothing about the page.
    Volt::test('console.connections.index')->assertOk()->assertSee('Single sign-on');
    Volt::test('console.connections.create')->assertOk();

    expect(Route::has('connections.show'))->toBeTrue()
        ->and(Route::has('connections.create'))->toBeTrue();
})->group('security');

it('creates a connection against the organization from the scope', function (): void {
    // The create form used to carry its own organization picker on the environment plane
    // and none on the organization plane — the second place the answer lived, validated
    // differently in each.
    $orgId = anEnvironmentAdminActingOn('tenant-sso-scoped');

    Volt::test('console.connections.create')
        ->set('type', 'saml')
        ->set('name', 'Acme Okta')
        ->set('idp_entity_id', 'https://idp.corp/metadata')
        ->set('idp_sso_url', 'https://idp.corp/sso')
        ->set('idp_x509cert', '-----BEGIN CERTIFICATE-----MIIB-----END CERTIFICATE-----')
        ->set('sp_entity_id', 'https://sp.acme/metadata')
        ->set('sp_acs_url', 'https://sp.acme/acs')
        ->call('create')
        ->assertHasNoErrors();

    expect(Connection::query()->where('organization_id', $orgId)->where('name', 'Acme Okta')->exists())->toBeTrue();
})->group('security');

it('refuses to create a connection before an organization is chosen', function (): void {
    anEnvironmentAdminActingOn('tenant-sso-unchosen');
    session()->forget(ConsoleScope::SELECTION_KEY);

    Volt::test('console.connections.create')
        ->set('type', 'saml')
        ->set('name', 'Acme Okta')
        ->set('idp_entity_id', 'https://idp.corp/metadata')
        ->set('idp_sso_url', 'https://idp.corp/sso')
        ->set('idp_x509cert', '-----BEGIN CERTIFICATE-----MIIB-----END CERTIFICATE-----')
        ->set('sp_entity_id', 'https://sp.acme/metadata')
        ->set('sp_acs_url', 'https://sp.acme/acs')
        ->call('create')
        ->assertHasErrors('name');

    expect(Connection::query()->exists())->toBeFalse();
})->group('security');

it('gives the organization plane the edit, disable and delete it never had', function (): void {
    // The reason an earlier attempt at this merge was reverted: it routed both planes at
    // the organization component, which had none of these three. Losing them means a
    // tenant admin cannot correct a rotated certificate, cannot switch a misconfigured
    // IdP off, and cannot remove one at all.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $connection = aSamlConnection($org->id);

    Volt::test('console.connections.show', ['connection' => $connection->id])
        ->set('editName', 'Corporate SAML (renamed)')
        ->call('saveConfig')
        ->assertHasNoErrors();
    expect(Connection::query()->whereKey($connection->id)->value('name'))->toBe('Corporate SAML (renamed)');

    Volt::test('console.connections.show', ['connection' => $connection->id])->call('activate')->assertHasNoErrors();
    expect($connection->fresh()?->isActive())->toBeTrue();

    Volt::test('console.connections.show', ['connection' => $connection->id])->call('disable')->assertHasNoErrors();
    expect($connection->fresh()?->status)->toBe(Cbox\Id\Federation\Enums\ConnectionStatus::Inactive);

    Volt::test('console.connections.show', ['connection' => $connection->id])->call('deleteConnection');
    expect(Connection::query()->whereKey($connection->id)->exists())->toBeFalse();
})->group('security');

it('gives the environment plane domain verification and the Admin Portal invite', function (): void {
    // The other half of the union. Neither existed on the environment console, so an
    // operator configuring SSO for a tenant could not prove the tenant's email domain —
    // which is the thing that routes their people to the IdP at all.
    $orgId = anEnvironmentAdminActingOn('tenant-sso-domains');

    Volt::test('console.connections.index')
        ->set('domain', 'ACME.com') // upper-case → normalized to lowercase
        ->call('addDomain')
        ->assertHasNoErrors();

    expect(VerifiedDomain::query()->where('organization_id', $orgId)->where('domain', 'acme.com')->exists())->toBeTrue();

    $component = Volt::test('console.connections.index')->call('invite')->assertHasNoErrors();
    expect($component->get('portalUrl'))->toContain('/setup/');
})->group('security');

it('attributes an Admin Portal link to the environment administrator who minted it', function (): void {
    // actorId(), not CurrentUser::id(): the environment plane has no subject session, so
    // the organization page's reader would have recorded '' as the creator of a link
    // that hands SSO configuration to an outsider.
    $orgId = anEnvironmentAdminActingOn('tenant-sso-actor');
    $actor = app(ConsoleScope::class)->actorId();

    Volt::test('console.connections.index')->call('invite')->assertHasNoErrors();

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
    Volt::test('console.connections.show', ['connection' => $theirs->id])->assertStatus(404);
    Volt::test('console.connections.index')->assertDontSee('Not yours');

    expect(Connection::query()->whereKey($theirs->id)->value('name'))->toBe('Not yours');
})->group('security');

it('refuses another organization\'s domain to an organization admin', function (): void {
    // The same escalation on the domain half: capture on a domain you do not own would
    // force that company's people through YOUR identity provider.
    actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'other-sso-domains'));
    $foreign = app(DomainVerification::class)->add($other->id, 'foreign.example');

    Volt::test('console.connections.index')->call('verifyDomain', $foreign->id)->assertForbidden();
    Volt::test('console.connections.index')->call('toggleCapture', $foreign->id)->assertForbidden();
    Volt::test('console.connections.index')->call('removeDomain', $foreign->id)->assertForbidden();

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

    Volt::test('console.connections.index')
        ->assertSee('First IdP')
        ->assertSee('Second IdP')
        // Domain verification and the portal invite belong to ONE organization, so they
        // wait for a choice rather than acting on whichever the page happened to load.
        ->assertSee('Choose an organization');
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

    Volt::test('console.connections.index')->assertForbidden();
})->group('security');
