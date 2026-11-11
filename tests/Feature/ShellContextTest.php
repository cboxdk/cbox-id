<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\Organizations\OrganizationLinks;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * THE HEADER'S `Organization ▾ / Project ▾ / Environment ▾`, as the server decides it:
 * the shared shell's levels, their menus (`/shell/context/{level}`) and the switch
 * (`/shell/switch`).
 *
 * Every row in those menus is a door: an organization to switch into, an environment to
 * open. So what these assert is the authorization a menu implies — that it lists exactly
 * what the person may open, never another customer's estate, and that choosing a row lands
 * where its label says.
 */

/**
 * A workspace with two environments in one project, and a second customer beside it whose
 * environment must never appear in the first one's menus.
 *
 * @return array{subjectId: string, organization: Organization, project: Project, production: Environment, sandbox: Environment, foreign: Environment}
 */
function contextEstate(): array
{
    multiTenantDeployment();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    $acme = provisionAccount('owner@context.example');
    $sandbox = app(TenantProvisioner::class)->addEnvironment($acme['project'], 'Sandbox', type: EnvironmentType::Sandbox);

    $globex = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Globex',
        ownerEmail: 'owner@globex.example',
        ownerName: 'Globex Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    return [
        'subjectId' => $acme['subjectId'],
        'organization' => $acme['organization'],
        'project' => $acme['project'],
        'production' => $acme['environment'],
        'sandbox' => $sandbox,
        'foreign' => $globex->environment,
    ];
}

/**
 * The header's levels on a page, by key.
 *
 * @return array<string, array<string, mixed>>
 */
function headerLevels(string $url): array
{
    return collect((array) test()->get($url)->assertOk()->inertiaProps('shell.context.levels'))->keyBy('key')->all();
}

/**
 * One level's menu, as the header asks for it.
 *
 * @param  array<string, string>  $ancestors
 * @return list<array<string, mixed>>
 */
function levelMenu(string $level, array $ancestors = [], string $base = 'https://cboxid.com'): array
{
    $query = $ancestors === [] ? '' : '?'.http_build_query(['ancestors' => $ancestors]);

    return (array) test()->getJson($base.'/shell/context/'.$level.$query)->assertOk()->json('data');
}

/**
 * Where choosing a row from a page lands.
 *
 * @param  array<string, string>  $select
 */
function switchLandsOn(array $select, string $from, string $base = 'https://cboxid.com'): string
{
    return (string) test()->postJson($base.'/shell/switch', ['select' => $select, 'from' => $from])->assertOk()->json('url');
}

it('lists the organization, its project and the environments its owner may open — and no other customer\'s', function (): void {
    $estate = contextEstate();
    signInAsMember($estate['subjectId']);
    $organization = $estate['organization']->id;
    $project = $estate['project']->id;

    $levels = headerLevels('https://cboxid.com'.route('orgs.projects', absolute: false));

    expect($levels['organization']['current'])->toMatchArray(['id' => $organization, 'name' => 'Acme'])
        ->and(array_column(levelMenu('organization'), 'id'))->toBe([$organization])
        // On the organization's own page no project is current: this IS the organization.
        ->and($levels['project']['current'] ?? null)->toBeNull()
        ->and(array_column(levelMenu('project', ['organization' => $organization]), 'id'))->toBe([$project]);

    $environments = levelMenu('environment', ['organization' => $organization, 'project' => $project]);

    expect(array_column($environments, 'id'))->toBe([$estate['production']->id, $estate['sandbox']->id])
        ->and(array_column($environments, 'tone'))->toBe(['production', 'sandbox'])
        ->and(array_column($environments, 'id'))->not->toContain($estate['foreign']->id);

    // Each one straight to its console's address on this host, which checks access itself
    // — and from Projects, which no environment console has, on its home page.
    foreach ($environments as $environment) {
        expect(switchLandsOn(['project' => $project, 'environment' => $environment['id']], route('orgs.projects', absolute: false)))
            ->toBe('/'.app(EnvironmentConsolePath::class)->for($environment['id']));
    }

    // Another customer's environment is not one to open from here.
    test()->postJson('https://cboxid.com/shell/switch', ['select' => ['project' => $project, 'environment' => $estate['foreign']->id], 'from' => route('orgs.projects', absolute: false)])
        ->assertStatus(404);
});

it('names every organization the person belongs to, and only those', function (): void {
    $estate = contextEstate();

    // A member of Globex too — and of nothing else.
    $globexId = app(PlatformRoot::class)->run(
        fn () => app(Organizations::class)->bySlug('globex')?->id,
    );
    expect($globexId)->toBeString();
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->add($globexId, $estate['subjectId'], MembershipRole::Developer));

    signInAsMember($estate['subjectId']);

    $levels = headerLevels('https://cboxid.com'.route('orgs.projects', absolute: false));

    expect(collect(levelMenu('organization'))->pluck('name')->sort()->values()->all())->toBe(['Acme', 'Globex'])
        ->and($levels['organization']['current']['name'])->toBe('Acme')
        // Belonging to Globex does not put Globex's environments in ACME's menus: those
        // are one switch away, answered by Globex's own access rules when it is current.
        ->and(array_column(levelMenu('environment', ['organization' => $estate['organization']->id, 'project' => $estate['project']->id]), 'id'))
        ->not->toContain($estate['foreign']->id);
});

it('carries the page being read into the environment it opens', function (): void {
    $estate = contextEstate();
    signInAsMember($estate['subjectId']);

    // Workspace › Keys is the same capability as an environment's Developers › API keys, so
    // opening production from here opens production's API keys.
    expect(switchLandsOn(['project' => $estate['project']->id, 'environment' => $estate['production']->id], route('orgs.settings.api-keys', absolute: false)))
        ->toBe('/'.app(EnvironmentConsolePath::class)->for($estate['production']->id).'/keys');
});

it('lists only the environments a scoped member was granted', function (): void {
    $estate = contextEstate();

    [$developer, $developerId] = addMember($estate['organization']->id, MembershipRole::Developer, 'scoped@context.example');
    grantNativeProductRoles($developer, ['developer']);
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->setEnvironmentAccess(
        $estate['organization']->id,
        $developerId,
        false,
        [$estate['sandbox']->id],
    ));

    signInAsMember($developerId);

    expect(array_column(levelMenu('environment', ['organization' => $estate['organization']->id, 'project' => $estate['project']->id]), 'id'))
        ->toBe([$estate['sandbox']->id]);
});

it('offers no operational environment to a Billing-only member', function (): void {
    $estate = contextEstate();

    [$member, $viewerId] = addMember($estate['organization']->id, MembershipRole::Viewer, 'viewer@context.example');
    grantNativeProductRoles($member, ['billing']);
    signInAsMember($viewerId);

    test()->get('https://cboxid.com'.orgPath('/projects'))->assertForbidden();
    $levels = headerLevels('https://cboxid.com'.orgPath('/settings/billing').'?organization='.$estate['organization']->id);

    expect($levels['organization']['current']['name'])->toBe('Acme')
        ->and(levelMenu('project', ['organization' => $estate['organization']->id]))->toBe([]);

    // …and a project it was not offered is not one whose environments it can list.
    test()->getJson('https://cboxid.com/shell/context/environment?'.http_build_query(['ancestors' => ['organization' => $estate['organization']->id, 'project' => $estate['project']->id]]))
        ->assertNotFound();
});

it('gives the environment console every environment it may switch to, landing on the same page', function (): void {
    $estate = contextEstate();

    serveOnTestHost($estate['production']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($estate['production']->id));
    actAsEnvironmentAdmin($estate['subjectId'], $estate['production']->id);

    // It used to be sent `organizations: []` and `environments: []` — a back arrow and the
    // environment's name, and nothing to switch to.
    $levels = headerLevels(route('environment.users'));

    expect($levels['organization']['current'])->toMatchArray(['name' => 'Acme'])
        ->and($levels['project']['current']['id'])->toBe($estate['project']->id)
        ->and($levels['environment']['current']['id'])->toBe($estate['production']->id);

    $environments = levelMenu('environment', ['organization' => $estate['organization']->id, 'project' => $estate['project']->id], 'http://cboxid.com');

    expect(array_column($environments, 'id'))->toBe([$estate['production']->id, $estate['sandbox']->id]);

    // The same page in the other environment, on the same host and the same session…
    $url = switchLandsOn(['environment' => $estate['sandbox']->id], (string) parse_url(route('environment.users'), PHP_URL_PATH), 'http://cboxid.com');

    expect($url)->toBe('/'.app(EnvironmentConsolePath::class)->for($estate['sandbox']->id).'/users');

    // …and it opens, without a sign-in or a handoff in between.
    $this->get('http://cboxid.com'.$url)->assertOk();
});

it('names a linked organization by its Cbox slug in the environment switcher, the address its console answers to', function (): void {
    $estate = contextEstate();

    $cbox = Environment::query()->create([
        'name' => 'Cbox', 'slug' => 'cbox', 'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active, 'is_default' => false, 'settings' => [],
    ]);
    $cboxOrganization = Str::lower((string) Str::ulid());
    DB::table('organizations')->insert([
        'id' => $cboxOrganization, 'environment_id' => $cbox->id, 'name' => 'Lovelace Labs',
        'slug' => 'lovelace-labs', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $links = app(OrganizationLinks::class);
    $links->flush();
    $links->link($estate['organization']->id, $cboxOrganization);

    serveOnTestHost($estate['production']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($estate['production']->id));
    actAsEnvironmentAdmin($estate['subjectId'], $estate['production']->id);

    $this->get(route('environment.users'))->assertOk();
    $url = switchLandsOn(['environment' => $estate['sandbox']->id], (string) parse_url(route('environment.users'), PHP_URL_PATH), 'http://cboxid.com');

    expect(app(EnvironmentConsolePath::class)->for($estate['sandbox']->id))->toStartWith('orgs/lovelace-labs/')
        ->and($url)->toBe('/'.app(EnvironmentConsolePath::class)->for($estate['sandbox']->id).'/users');

    $this->get('http://cboxid.com'.$url)->assertOk();
});

it('lands a detail page on its list, and the home page on the home page', function (): void {
    $estate = contextEstate();

    serveOnTestHost($estate['production']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($estate['production']->id));
    actAsEnvironmentAdmin($estate['subjectId'], $estate['production']->id);

    // An organization of THIS environment has no counterpart in the next one, so its page
    // lands on the list it came from rather than on a 404.
    $tenant = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co-context'));
    $sandbox = '/'.app(EnvironmentConsolePath::class)->for($estate['sandbox']->id);

    expect(switchLandsOn(['environment' => $estate['sandbox']->id], (string) parse_url(route('environment.organizations.show', $tenant->id), PHP_URL_PATH), 'http://cboxid.com'))
        ->toBe($sandbox.'/organizations')
        ->and(switchLandsOn(['environment' => $estate['sandbox']->id], (string) parse_url(route('environment.home'), PHP_URL_PATH), 'http://cboxid.com'))
        ->toBe($sandbox);
});

it('calls an organization that owns no projects an organization, with nothing below it', function (): void {
    // A single-tenant install's organization: the console IS its product, so there is no
    // project or environment above it to switch between.
    $subject = app(Subjects::class)->create('plain@acme.test', 'Plain Admin', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Plain Co', 'plain-co-context'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $levels = headerLevels(route('dashboard'));

    expect($levels['organization']['label'])->toBe('Organization')
        ->and($levels['organization']['current']['name'])->toBe('Plain Co')
        ->and($levels['project']['current'] ?? null)->toBeNull()
        ->and(levelMenu('project', ['organization' => $org->id], rtrim(url('/'), '/')))->toBe([]);
});
