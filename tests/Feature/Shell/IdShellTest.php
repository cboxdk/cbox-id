<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\Organizations\OrganizationDirectory;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * THE SHARED SHELL (laravel-console-kit → cbox-ui `CboxShell`), per surface.
 *
 * An environment console: Organization › Project › Environment, with the environment's tone
 * and the sandbox strip on a sandbox. Platform admin: no levels and the "whole deployment"
 * notice. A customer environment's own console keeps its white-labelled chrome. Nothing the
 * shell reads calls another service.
 */
function addSandbox(Environment $production, string $name): Environment
{
    $project = Project::query()->findOrFail($production->project_id);

    return app(EnvironmentContext::class)->runAs(
        GenericEnvironment::of((string) app(PlatformRoot::class)->environment()?->environmentKey()),
        static fn (): Environment => app(TenantProvisioner::class)->addEnvironment($project, $name, null, EnvironmentType::Sandbox),
    );
}

/** A path on the app host, where the console and the shell's endpoints are served. */
function onAppHost(string $path): string
{
    $host = config('cbox-id.tenancy.account_host');

    return 'http://'.(is_string($host) && $host !== '' ? $host : 'localhost').$path;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('puts an environment console in its organization, project and environment', function (): void {
    actAsEnvironmentAdminOfATenant();

    $shell = (array) $this->get(envConsole('/users'))->assertOk()->inertiaProps('shell');

    expect(array_column($shell['context']['levels'], 'key'))->toBe(['organization', 'project', 'environment'])
        ->and($shell['context']['levels'][2]['current']['tone'])->toBe('production')
        ->and($shell['context']['levels'][2]['current']['testData'])->toBeFalse()
        ->and($shell['context']['switchUrl'])->toBe('/shell/switch')
        ->and($shell['app']['key'])->toBe('id')
        ->and($shell['nav']['activePage'])->toBe('environment.users')
        ->and($shell)->not->toHaveKey('banner');
})->group('shell');

it('shows the sandbox strip in a sandbox, and its environments production first', function (): void {
    $environmentId = actAsEnvironmentAdminOfATenant();
    $production = Environment::query()->findOrFail($environmentId);
    $sandbox = addSandbox($production, 'Aardvark sandbox');
    useEnvironmentConsole((string) $sandbox->getKey());

    $shell = (array) $this->get(envConsole('/users'))->assertOk()->inertiaProps('shell');
    $environment = $shell['context']['levels'][2];

    expect($environment['current'])->toMatchArray(['name' => 'Aardvark sandbox', 'tone' => 'sandbox', 'testData' => true])
        ->and($shell['banner']['kind'])->toBe('sandbox');

    $list = $this->getJson(onAppHost($environment['listUrl']))->assertOk()->json('data');

    expect(array_column($list, 'tone'))->toBe(['production', 'sandbox']);
})->group('shell');

it('keeps the page when switching environment, and falls back to the list from a record', function (): void {
    $environmentId = actAsEnvironmentAdminOfATenant();
    $production = Environment::query()->findOrFail($environmentId);
    $sandbox = addSandbox($production, 'Sandbox');
    $from = envConsolePath('/users');
    $sandboxPath = preg_replace('#/environments/[^/]+$#', '/environments/'.$sandbox->slug, (string) URL::getDefaultParameters()[EnvironmentConsolePath::PARAMETER]);

    $this->postJson(onAppHost('/shell/switch'), ['select' => ['environment' => (string) $sandbox->getKey()], 'from' => $from])
        ->assertOk()
        ->assertJson(['url' => '/'.$sandboxPath.'/users', 'resolution' => 'same_page']);

    // From the header's cascade, which posts the project with the environment.
    $project = (string) $sandbox->project_id;

    $this->postJson(onAppHost('/shell/switch'), ['select' => ['project' => $project, 'environment' => (string) $sandbox->getKey()], 'from' => envConsolePath('/users/01JUSER')])
        ->assertOk()
        ->assertJson(['url' => '/'.$sandboxPath.'/users', 'resolution' => 'list_fallback']);
})->group('shell');

it('shows platform admin with no context levels and the whole-deployment notice', function (): void {
    multiTenantDeployment();
    actAsOperator();

    $shell = (array) $this->get('https://cboxid.com/admin/organizations')->assertOk()->inertiaProps('shell');

    expect($shell['context']['levels'])->toBe([])
        ->and($shell['banner']['kind'])->toBe('notice')
        ->and($shell['banner']['text'])->toContain('The whole deployment');
})->group('shell');

it('rings the bell only while an approval waits', function (): void {
    actAsEnvironmentAdminOfATenant();

    $shell = (array) $this->get(envConsole('/users'))->assertOk()->inertiaProps('shell');

    expect($shell['notifications'])->toBe(['enabled' => false]);
})->group('shell');

/**
 * An agent key held for `$approver`: every write it makes waits for that person's approval.
 * Returns the request it raised.
 */
function shellHeldActionFor(string $environmentId, string $approver): string
{
    $issued = app(EnvironmentApiKeys::class)->issue($environmentId, 'Agent of '.$approver, ['keys:read', 'keys:write'], null, new KeyProvenance(
        createdByType: 'organization_member',
        createdById: $approver,
        stepUpPolicy: ['min_danger' => 'critical', 'actions' => []],
    ));

    $id = (string) test()->withToken($issued->plaintext)->postJson('/api/v1/keys', ['name' => 'X', 'scopes' => ['keys:read']])->assertStatus(202)->json('approval.id');
    test()->flushHeaders();

    return $id;
}

it('rings the bell for an approval waiting for this person, and not for one waiting for somebody else', function (): void {
    multiTenantDeployment();
    $account = provisionAccount('bell@acme.example');
    serveOnTestHost($account['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($account['environment']->id));

    // Somebody else's first: the admin's bell stays quiet.
    $colleague = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->create('colleague@acme.example', 'Colleague', 'a-strong-unbreached-passphrase')->id);
    shellHeldActionFor($account['environment']->id, $colleague);
    actAsEnvironmentAdmin($account['subjectId'], $account['environment']->id);

    expect((array) $this->get(envConsole('/users'))->assertOk()->inertiaProps('shell.notifications'))->toBe(['enabled' => false]);

    shellHeldActionFor($account['environment']->id, $account['subjectId']);

    expect((array) $this->get(envConsole('/users'))->assertOk()->inertiaProps('shell.notifications'))
        ->toBe(['enabled' => true, 'unread' => 1, 'url' => route('environment.approvals', [], false)]);
})->group('shell');

/**
 * Two organizations the same person owns, each with a member only it has.
 *
 * @return array{subjectId: string, a: string, b: string, aId: string, bId: string}
 */
function shellTwoOrganizations(): array
{
    multiTenantDeployment();
    $acme = provisionAccount('owner@two.example');
    $globex = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Globex',
        ownerEmail: 'owner@globex.example',
        ownerName: 'Globex Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->add($globex->organization->id, $acme['subjectId'], MembershipRole::Owner));

    addMember($acme['organization']->id, MembershipRole::Developer, 'aonly@search.example', 'Acme Searchable');
    addMember($globex->organization->id, MembershipRole::Developer, 'bonly@search.example', 'Globex Searchable');

    $directory = app(OrganizationDirectory::class);

    return [
        'subjectId' => $acme['subjectId'],
        'a' => (string) $directory->slugOf($acme['organization']->id),
        'b' => (string) $directory->slugOf($globex->organization->id),
        'aId' => $acme['organization']->id,
        'bId' => $globex->organization->id,
    ];
}

it('puts an organization page in its organization, and searches records behind that organization\'s door', function (): void {
    $orgs = shellTwoOrganizations();
    signInAsMember($orgs['subjectId']);

    $shell = (array) $this->get(onAppHost('/orgs/'.$orgs['b'].'/projects'))->assertOk()->inertiaProps('shell');

    expect($shell['context']['levels'][0]['current']['id'])->toBe($orgs['bId'])
        ->and($shell['palette']['searchUrl'])->toStartWith('/orgs/'.$orgs['b'].'/search?')
        ->and($shell['notifications'])->toBe(['enabled' => false])
        ->and($shell['app']['key'])->toBe('id');
})->group('shell');

it('answers records from the organization the address names, not the session\'s', function (): void {
    $orgs = shellTwoOrganizations();
    // The session's organization is Acme (the first membership); the address says Globex.
    signInAsMember($orgs['subjectId']);

    $titles = array_column((array) $this->getJson(onAppHost('/orgs/'.$orgs['b'].'/search?group=records&q=searchable'))->assertOk()->json('groups.0.items'), 'title');

    expect($titles)->toContain('Globex Searchable')->not->toContain('Acme Searchable');

    $titles = array_column((array) $this->getJson(onAppHost('/orgs/'.$orgs['a'].'/search?group=records&q=searchable'))->assertOk()->json('groups.0.items'), 'title');

    expect($titles)->toContain('Acme Searchable')->not->toContain('Globex Searchable');
})->group('security');

it('refuses records to a member who does not administer the organization', function (): void {
    $orgs = shellTwoOrganizations();
    [, $memberId] = addMember($orgs['aId'], MembershipRole::Member, 'plain@search.example', 'Plain Member');
    signInAsMember($memberId);

    $this->getJson(onAppHost('/orgs/'.$orgs['a'].'/search?group=records&q=searchable'))->assertForbidden();

    // …and the kit's own search, bound to no organization, answers no records at all.
    $groups = (array) $this->getJson(onAppHost('/shell/search?q=searchable&group=records'))->assertOk()->json('groups');

    expect(collect($groups)->flatMap(fn (array $group): array => $group['items'])->all())->toBe([]);

    // Somebody else's organization is not a door at all.
    $this->getJson(onAppHost('/orgs/'.$orgs['b'].'/search?group=records&q=searchable'))->assertNotFound();
})->group('security');

it('answers the shell\'s own endpoints without calling another service', function (): void {
    $orgs = shellTwoOrganizations();
    signInAsMember($orgs['subjectId']);

    $this->getJson(onAppHost('/shell/search?q=searchable'))->assertOk();
    $this->getJson(onAppHost('/orgs/'.$orgs['a'].'/search?group=goto&q=glob'))->assertOk();
    $this->getJson(onAppHost('/switch-app/catalog'))->assertOk();
    $this->getJson(onAppHost('/shell/context/organization'))->assertOk();
    $this->getJson(onAppHost('/shell/context/project?'.http_build_query(['ancestors' => ['organization' => $orgs['aId']]])))->assertOk();
})->group('shell');

it('answers an environment console\'s "Go to" without calling another service', function (): void {
    actAsEnvironmentAdminOfATenant();

    $searchUrl = (string) $this->get(envConsole('/users'))->assertOk()->inertiaProps('shell.palette.searchUrl');

    expect($searchUrl)->toStartWith(route('environment.search', [], false));

    $this->getJson(onAppHost(route('environment.search', [], false).'?group=goto&q=users'))->assertOk();
    $this->getJson(onAppHost(route('environment.search', [], false).'?group=records&q=owner'))->assertOk();
})->group('shell');

it('creates from the header by name alone and lands on the details', function (): void {
    $orgs = shellTwoOrganizations();
    signInAsMember($orgs['subjectId']);

    $organization = $this->postJson(onAppHost('/shell/context/organization'), ['name' => 'Initech'])->assertCreated()->json();
    expect($organization['detailsUrl'])->toEndWith('/settings#finish')->toStartWith('/orgs/');

    $project = $this->postJson(onAppHost('/shell/context/project'), ['name' => 'Payments', 'ancestors' => ['organization' => $orgs['aId']]])->assertCreated()->json();
    expect($project['detailsUrl'])->toStartWith('/orgs/'.$orgs['a'].'/')->toEndWith('#finish');

    $environment = $this->postJson(onAppHost('/shell/context/environment'), ['name' => 'Staging', 'ancestors' => ['organization' => $orgs['aId'], 'project' => $project['id']]])->assertCreated()->json();
    expect($environment['detailsUrl'])->toEndWith('/get-started');
})->group('shell');

it('refuses inline create to a member, and to an ancestor from another organization', function (): void {
    $orgs = shellTwoOrganizations();
    $foreignProject = (string) app(PlatformRoot::class)->run(fn () => Project::query()->where('organization_id', $orgs['bId'])->value('id'));
    [, $memberId] = addMember($orgs['aId'], MembershipRole::Member, 'plain@create.example', 'Plain Member');
    signInAsMember($memberId);

    $this->postJson(onAppHost('/shell/context/project'), ['name' => 'Nope', 'ancestors' => ['organization' => $orgs['aId']]])->assertForbidden();
    $this->postJson(onAppHost('/shell/context/project'), ['name' => 'Nope', 'ancestors' => ['organization' => $orgs['bId']]])->assertNotFound();

    signInAsMember($orgs['subjectId']);
    $this->postJson(onAppHost('/shell/context/environment'), ['name' => 'Nope', 'ancestors' => ['organization' => $orgs['aId'], 'project' => $foreignProject]])->assertNotFound();

    expect(app(PlatformRoot::class)->run(fn () => Project::query()->where('name', 'Nope')->exists()))->toBeFalse();
})->group('security');

it('keeps a customer environment\'s own console in its white-labelled chrome', function (): void {
    multiTenantDeployment();
    $vendor = provisionAccount('vendor@chrome.example');
    serveOnTestHost($vendor['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($vendor['environment']->id));
    actingAsRole(MembershipRole::Owner);

    $response = $this->get(route('dashboard'))->assertOk();

    expect($response->inertiaProps('shell'))->toBeNull()
        ->and((array) $response->inertiaProps('tenantShell'))->toHaveKey('areas')
        ->and($response->inertiaProps('tenantShell.areas'))->not->toBe([]);
})->group('shell');
