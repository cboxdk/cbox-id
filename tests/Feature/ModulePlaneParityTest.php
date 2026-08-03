<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleArea;
use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\Health\ConsoleParityHealthCheck;
use App\Platform\Navigation\ConsoleNavigation;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Cbox\Id\Console\Enums\HealthStatus;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Cbox\Id\RiskPlus\Models\RiskEvent;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\Models\BrandProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * The six console MODULES, on both planes.
 *
 * Every one of them registered under `plane:subject` and none under `env.admin`, so an
 * environment administrator — the person who owns the environment — had no analytics, no
 * compliance exports, no connectors, no trusted devices, no risk events and no branding.
 * The parity health check did not cover them, so the doctor said the console was whole
 * while six of its capabilities were reachable from one door only.
 *
 * Six modules making the same mistake is an API that invites it, so most of what is
 * asserted here is about the registration rather than any one page: that declaring a page
 * gets both planes by default, that a page which genuinely belongs to one says so, and
 * that the doctor now measures the difference.
 */

/** One enrolled handset for a subject, built the way the module's own tests build them. */
function enrolledHandset(string $subjectId, string $name): Device
{
    $device = new Device;
    $device->fill([
        'subject_id' => $subjectId,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => $name,
        'status' => DeviceStatus::Active,
    ]);
    $device->save();

    return $device;
}

/** Sign in as an environment administrator, optionally acting on an organization. */
function moduleEnvironmentAdmin(string $slug = 'module-parity', bool $chooseOrganization = true): ?string
{
    platformRootEnvironment();

    $provisioned = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'module-parity-'.$slug.'@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->member, $provisioned->environment->id);

    if (! $chooseOrganization) {
        return null;
    }

    $organizationId = app(Organizations::class)->create(new NewOrganization('Tenant Co', $slug))->id;
    app(ConsoleScope::class)->chooseOrganization($organizationId);

    return $organizationId;
}

/** Every module feature on, so a feature gate cannot 404 a page this file is measuring. */
function everyModuleFeatureOn(): void
{
    config([
        'id-analytics.enabled' => true,
        'compliance.enabled' => true,
        'connectors.enabled' => true,
        'id-devices.enabled' => true,
    ]);
}

/*
|--------------------------------------------------------------------------
| The registration itself
|--------------------------------------------------------------------------
*/

/**
 * The defect, stated as a property rather than as six fixes: a module that says nothing
 * about planes is on both. Delete the environment half of ConsoleRoutes::page() and every
 * module in the loop fails here.
 */
it('routes every module page a module declared for both planes on both planes', function (): void {
    $declared = app(ConsolePages::class)->all();

    // An empty registry passes every assertion below and proves nothing — the modules are
    // vendored in, so nothing here means the registration stopped happening.
    expect($declared)->not->toBeEmpty();

    $bothPlanes = array_values(array_filter($declared, fn ($page): bool => $page->only === null));

    expect($bothPlanes)->not->toBeEmpty();

    foreach ($bothPlanes as $page) {
        expect(Route::has($page->routeOn(ConsolePlane::Organization)))
            ->toBeTrue("[{$page->route}] has no organization-plane route")
            ->and(Route::has($page->routeOn(ConsolePlane::Environment)))
            ->toBeTrue("[{$page->route}] has no environment-plane route");
    }
})->group('security');

/**
 * The other half of the same property: a single-plane page is single-plane because
 * someone said so, and it is exactly one page.
 */
it('serves the personal device page on the organization plane alone', function (): void {
    $personal = array_values(array_filter(
        app(ConsolePages::class)->all(),
        fn ($page): bool => $page->route === 'devices.mine',
    ));

    expect($personal)->toHaveCount(1)
        ->and($personal[0]->only)->toBe(ConsolePlane::Organization)
        ->and(Route::has('devices.mine'))->toBeTrue()
        // It lists the caller's OWN handsets, keyed to the signed-in subject. An
        // environment administrator is never a subject of the environment they
        // administer, so this page could only render empty there.
        ->and(Route::has('environment.devices.mine'))->toBeFalse();
})->group('security');

/**
 * The area map is what makes a plane-agnostic declaration possible at all, and a page
 * declared for both planes in an area that exists on one is a contradiction nobody would
 * otherwise notice — the page would simply be absent from a rail.
 */
it('refuses a both-planes page in an area the environment console does not have', function (): void {
    expect(fn () => app(ConsolePages::class)->add(
        area: ConsoleArea::Account,
        route: 'nonsense',
        label: 'Nonsense',
        feature: 'devices',
    ))->toThrow(LogicException::class);
})->group('security');

/** The rail an environment administrator actually sees. */
it('puts every module page in the environment rail', function (): void {
    everyModuleFeatureOn();

    $routes = (new ConsoleNavigation)->environment()->routes();

    expect($routes)->toContain('environment.sign-in-activity')
        ->and($routes)->toContain('environment.compliance.audit')
        ->and($routes)->toContain('environment.compliance.exports')
        ->and($routes)->toContain('environment.connectors.catalog')
        ->and($routes)->toContain('environment.connectors.connections')
        ->and($routes)->toContain('environment.risk-plus.events')
        ->and($routes)->toContain('environment.whitelabel.branding')
        ->and($routes)->toContain('environment.devices.index')
        // Personal, so it is not here — and its absence is what stops the rail linking
        // to a route that does not exist on this plane.
        ->and($routes)->not->toContain('environment.devices.mine');
});

/** A module switched off contributes no rail entry, on the plane that never had one. */
it('drops a module page from the environment rail when the module is off', function (): void {
    config()->set('id-devices.enabled', false);

    expect((new ConsoleNavigation)->environment()->routes())->not->toContain('environment.devices.index');
});

/** The doctor measures the modules now, which is the thing that was not watching. */
it('reports module plane parity in the doctor', function (): void {
    $bothPlanes = count(array_filter(
        app(ConsolePages::class)->all(),
        fn ($page): bool => $page->only === null,
    ));

    $results = app(ConsoleParityHealthCheck::class)->run();

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(HealthStatus::Ok)
        // The COUNT, not just the sentence: a check that stopped reading the registry
        // would still say "module pages reachable on both planes" about none of them,
        // which is exactly the reassuring silence this replaces.
        ->and($results[0]->detail)->toContain($bothPlanes.' module pages reachable on both planes')
        ->and($bothPlanes)->toBeGreaterThan(5);
});

/**
 * And it FAILS when a module does what all six of them did. Declared here rather than
 * inferred, because a health check nobody has watched fail is a health check nobody knows
 * the failure shape of.
 */
it('fails the doctor when a module declares both planes and routes one', function (): void {
    app(ConsolePages::class)->add(
        area: ConsoleArea::Logs,
        route: 'never-routed-anywhere',
        label: 'Never routed',
        feature: 'risk-plus',
    );

    $results = app(ConsoleParityHealthCheck::class)->run();

    expect($results[0]->status)->toBe(HealthStatus::Fail)
        ->and($results[0]->detail)->toContain('Never routed');
})->group('security');

/*
|--------------------------------------------------------------------------
| The pages, through the environment door
|--------------------------------------------------------------------------
*/

it('serves every module page to an environment administrator', function (): void {
    everyModuleFeatureOn();
    moduleEnvironmentAdmin('serves');

    foreach ([
        'environment.sign-in-activity',
        'environment.compliance.audit',
        'environment.compliance.exports',
        'environment.connectors.catalog',
        'environment.connectors.connections',
        'environment.risk-plus.events',
        'environment.whitelabel.branding',
        'environment.devices.index',
    ] as $route) {
        expect($this->get(route($route))->status())->toBe(200, "[{$route}] did not render on the environment plane");
    }
})->group('security');

it('refuses every module page to a browser holding no admin session at all', function (): void {
    everyModuleFeatureOn();
    platformRootEnvironment();

    foreach ([
        'environment.sign-in-activity',
        'environment.compliance.audit',
        'environment.connectors.catalog',
        'environment.risk-plus.events',
        'environment.whitelabel.branding',
        'environment.devices.index',
    ] as $route) {
        expect($this->get(route($route))->status())
            ->not->toBe(200, "[{$route}] rendered for a request with no session");
    }
})->group('security');

/*
|--------------------------------------------------------------------------
| The escalation the merges keep hitting: served on both planes, scoped on neither
|--------------------------------------------------------------------------
*/

/**
 * Risk events carry an email and no organization, so the organization plane recovers the
 * scope by matching member addresses. Serving the page on both planes without that filter
 * would be invisible on the environment plane — where the unscoped read is correct — and
 * a live feed of another tenant's credential stuffing on the other.
 */
it('shows an organization admin only their own members\' risk events', function (): void {
    everyModuleFeatureOn();
    platformRootEnvironment();

    $mine = app(Subjects::class)->create('mine@acme.test', 'Mine', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'risk-scope-acme'));
    app(Memberships::class)->add($org->id, $mine->id, MembershipRole::Owner);

    RiskEvent::query()->create([
        'action' => 'auth.login', 'outcome' => 'step_up', 'score' => 80,
        'reasons' => ['zarquon'], 'email' => 'mine@acme.test',
    ]);
    RiskEvent::query()->create([
        'action' => 'auth.login', 'outcome' => 'step_up', 'score' => 90,
        'reasons' => ['stranger'], 'email' => 'stranger@elsewhere.test',
    ]);

    actingAsRole(MembershipRole::Owner);
    app(Memberships::class)->add($org->id, app(CurrentUser::class)->id(), MembershipRole::Owner);

    $html = Volt::test('risk-plus.events')->html();

    expect($html)->not->toContain('stranger');
})->group('security');

/**
 * The environment plane's legitimate other half of the same page: the whole feed, which
 * is what a feed of attacks against the environment IS.
 */
it('shows an environment administrator the whole risk feed', function (): void {
    everyModuleFeatureOn();
    moduleEnvironmentAdmin('risk-env', chooseOrganization: false);

    RiskEvent::query()->create([
        'action' => 'auth.login', 'outcome' => 'step_up', 'score' => 90,
        'reasons' => ['stranger'], 'email' => 'stranger@elsewhere.test',
    ]);

    $this->get(route('environment.risk-plus.events'))->assertOk()->assertSee('stranger');
})->group('security');

/**
 * The device inventory is the same shape: keyed by subject, so an organization admin must
 * see only their own members' handsets while the environment administrator sees the
 * estate.
 */
it('shows an organization admin only their own members\' devices', function (): void {
    everyModuleFeatureOn();
    platformRootEnvironment();

    [$subjectId, $org] = actingAsRole(MembershipRole::Owner);

    $stranger = app(Subjects::class)->create('stranger@elsewhere.test', 'Stranger', 'a-strong-unbreached-passphrase');

    enrolledHandset($subjectId, 'Mine');
    enrolledHandset($stranger->id, 'Zarquon');

    $html = Volt::test('devices.index')->html();

    expect($html)->toContain('Mine')->not->toContain('Zarquon');
})->group('security');

/**
 * The scope, not console-kit's CurrentContext.
 *
 * `Console::context()->organizationId()` answers null whenever no SUBJECT is signed in —
 * which on the environment plane is ALWAYS — so the connectors pages read the
 * environment-wide branch even after an administrator narrowed to one organization. The
 * page says which it is doing, so the copy is what pins it: cheap, and it fails for the
 * right reason rather than needing four kinds of connector seeded to notice.
 */
it('narrows connectors to the organization an environment administrator chose', function (): void {
    everyModuleFeatureOn();
    moduleEnvironmentAdmin('connectors-scope');

    $this->get(route('environment.connectors.connections'))
        ->assertOk()
        ->assertSee('for this organization')
        ->assertDontSee('in this environment');
})->group('security');

it('shows the whole environment\'s connectors when none is chosen', function (): void {
    everyModuleFeatureOn();
    moduleEnvironmentAdmin('connectors-wide', chooseOrganization: false);

    $this->get(route('environment.connectors.connections'))
        ->assertOk()
        ->assertSee('in this environment');
})->group('security');

/**
 * The audit trail reads the chosen organization's chain — BOTH directions asserted.
 *
 * "Does not show the other tenant" alone is satisfied by a page that shows nothing, and
 * on this page that is a live possibility rather than a hypothetical: the reader answers
 * a null organization with `whereNull('organization_id')`, so an unscoped read renders an
 * empty table and a one-sided test would call that isolation. So the acting
 * organization's own entry must be there, and the other's must not.
 */
it('reads the acting organization\'s audit chain and no other', function (): void {
    everyModuleFeatureOn();
    $organizationId = moduleEnvironmentAdmin('audit-scope');

    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'audit-scope-other'));

    app(AuditLog::class)->record(new AuditEvent(
        action: 'ours.happened',
        actorType: ActorType::System,
        organizationId: (string) $organizationId,
    ));

    app(AuditLog::class)->record(new AuditEvent(
        action: 'zarquon.happened',
        actorType: ActorType::System,
        organizationId: $other->id,
    ));

    $this->get(route('environment.compliance.audit'))
        ->assertOk()
        ->assertSee('ours.happened')
        ->assertDontSee('zarquon.happened');
})->group('security');

/** The same page, the other door — an organization admin sees their own chain only. */
it('reads an organization admin\'s own audit chain and no other', function (): void {
    everyModuleFeatureOn();
    platformRootEnvironment();

    [, $org] = actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Other Co', 'audit-org-other'));

    app(AuditLog::class)->record(new AuditEvent(
        action: 'ours.happened',
        actorType: ActorType::System,
        organizationId: $org->id,
    ));

    app(AuditLog::class)->record(new AuditEvent(
        action: 'zarquon.happened',
        actorType: ActorType::System,
        organizationId: $other->id,
    ));

    expect(Volt::test('compliance.audit')->html())
        ->toContain('ours.happened')
        ->not->toContain('zarquon.happened');
})->group('security');

/**
 * The run history has no organization to be scoped by, so the decision is who may see the
 * whole of it — and "there is nothing to filter on" must not resolve to "show everyone".
 */
it('keeps the environment-wide export history off the organization plane', function (): void {
    everyModuleFeatureOn();
    platformRootEnvironment();
    actingAsRole(MembershipRole::Owner);

    AuditExportRun::query()->create([
        'status' => AuditExportRun::STATUS_COMPLETED,
        'scopes_scanned' => 17, 'entries_exported' => 4200, 'batches' => 1,
        'sink' => 'Acme\\ZarquonSink', 'started_at' => now(), 'finished_at' => now(),
    ]);

    expect(Volt::test('compliance.exports')->html())
        ->not->toContain('Zarquon')
        ->not->toContain('Recent export runs');
})->group('security');

it('shows the export history to the administrator who owns the environment', function (): void {
    everyModuleFeatureOn();
    moduleEnvironmentAdmin('export-history', chooseOrganization: false);

    AuditExportRun::query()->create([
        'status' => AuditExportRun::STATUS_COMPLETED,
        'scopes_scanned' => 17, 'entries_exported' => 4200, 'batches' => 1,
        'sink' => 'Acme\\ZarquonSink', 'started_at' => now(), 'finished_at' => now(),
    ]);

    $this->get(route('environment.compliance.exports'))->assertOk()->assertSee('Zarquon');
})->group('security');

/*
|--------------------------------------------------------------------------
| Branding: the module where null is a capability rather than an absence
|--------------------------------------------------------------------------
*/

it('edits the environment default when no organization is chosen', function (): void {
    moduleEnvironmentAdmin('brand-default', chooseOrganization: false);

    Volt::test('whitelabel.branding')
        ->set('appName', 'Environment Wide')
        ->call('save')
        ->assertHasNoErrors();

    $profile = app(BrandProfiles::class)->forEnvironment();

    expect($profile?->app_name)->toBe('Environment Wide')
        ->and($profile?->organization_id)->toBeNull();
})->group('security');

it('edits the chosen organization\'s profile and leaves the environment default alone', function (): void {
    $organizationId = moduleEnvironmentAdmin('brand-org');

    Volt::test('whitelabel.branding')
        ->set('appName', 'Just This Tenant')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(BrandProfiles::class)->forOrganization((string) $organizationId)?->app_name)->toBe('Just This Tenant')
        ->and(app(BrandProfiles::class)->forEnvironment())->toBeNull();
})->group('security');

/**
 * The hole the earlier fix closed, kept closed now that the page serves both planes: an
 * organization admin must not be able to reach the row every other tenant inherits.
 */
it('never lets an organization admin write the environment default', function (): void {
    platformRootEnvironment();
    actingAsRole(MembershipRole::Owner);

    BrandProfile::query()->create(['organization_id' => null, 'app_name' => 'Environment Wide']);

    Volt::test('whitelabel.branding')
        ->set('appName', 'Hijacked')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(BrandProfiles::class)->forEnvironment()?->app_name)->toBe('Environment Wide');
})->group('security');
