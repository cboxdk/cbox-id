<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Governance\Models\CertificationCampaign;
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
