<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
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
