<?php

declare(strict_types=1);

use App\Http\Middleware\BindOrganizationFromPath;
use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\CurrentUser;
use App\Platform\Organizations\LastUsedOrganization;
use App\Platform\Organizations\OrganizationDirectory;
use App\Platform\PlatformAuth;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| THE ORGANIZATION IN THE ADDRESS: `/orgs/{org}/…`
|--------------------------------------------------------------------------
| The Cbox organization console lives under `/orgs/{org}`, the same address every Cbox
| app gives an organization. `{org}` is read against the signed-in person's own
| memberships on every request, and the session never decides which organization a
| page acts on: it only remembers the last one, for `/`.
*/

/**
 * One person in two organizations, signed in, with both admitted to the whole console.
 *
 * @return array{first: array<string, mixed>, second: array<string, mixed>, firstSlug: string, secondSlug: string}
 */
function twoOrganizations(): array
{
    $first = provisionAccount('two-orgs@example.test');
    $second = provisionAccount('second-owner@example.test');
    $membership = app(PlatformRoot::class)->run(fn () => app(Memberships::class)->add($second['organization']->id, $first['subjectId'], MembershipRole::Admin));
    grantNativeProductRoles($membership, ['admin']);
    signInAsMember($first['subjectId']);

    return [
        'first' => $first,
        'second' => $second,
        'firstSlug' => orgSlug($first['organization']->id),
        'secondSlug' => orgSlug($second['organization']->id),
    ];
}

/** @return list<RoutingRoute> every route of the organization console */
function organizationConsoleRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'orgs/{org}'),
    ));
}

/** A URL for a route under `/orgs/{org}` in organization `$slug`, every other parameter a placeholder. */
function organizationConsoleUrl(RoutingRoute $route, string $slug): string
{
    $path = str_replace('{org}', $slug, $route->uri());

    return '/'.preg_replace('/\{[^}]+\}/', '01hzzzzzzzzzzzzzzzzzzzzzzz', $path);
}

it('binds every organization-console route to the organization in its path', function (): void {
    $routes = organizationConsoleRoutes();

    // A walk over nothing proves nothing: projects, members, keys, domains, settings, billing.
    expect(count($routes))->toBeGreaterThan(30);

    foreach ($routes as $route) {
        expect($route->parameterNames()[0] ?? null)->toBe('org', "[{$route->getName()}] does not start at the organization")
            ->and(in_array('orgs.bind', $route->gatherMiddleware(), true) || in_array(BindOrganizationFromPath::class, $route->gatherMiddleware(), true))
            ->toBeTrue("[{$route->getName()}] does not bind the organization from its path")
            ->and((string) $route->getName())->toStartWith('orgs.');
    }
})->group('security');

it('answers somebody else\'s organization, and one that does not exist, with 404 on every route', function (): void {
    $mine = provisionAccount('mine@example.test');
    $theirs = provisionAccount('theirs@example.test');
    signInAsMember($mine['subjectId']);

    $foreign = orgSlug($theirs['organization']->id);

    foreach (organizationConsoleRoutes() as $route) {
        $method = collect($route->methods())->first(fn (string $verb): bool => $verb !== 'HEAD') ?? 'GET';

        foreach ([$foreign, 'no-such-organization'] as $slug) {
            nextRequest();
            $this->call($method, organizationConsoleUrl($route, $slug))
                ->assertNotFound();
        }
    }

    // …and nothing about the session moved: the foreign address switched nobody anywhere.
    expect(session(PlatformAuth::ORG_KEY))->toBe($mine['organization']->id);
})->group('security');

it('acts on the organization in the address, not the one the session was last in', function (): void {
    ['first' => $first, 'second' => $second, 'secondSlug' => $secondSlug] = twoOrganizations();

    expect(session(PlatformAuth::ORG_KEY))->toBe($first['organization']->id);

    $this->get(route('orgs.settings.members', ['org' => $secondSlug]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('organization', $second['organization']->id));

    // Opening it by address binds it for that request only: the session, and every tab
    // that still reads it, stays in the organization it was in.
    expect(session(PlatformAuth::ORG_KEY))->toBe($first['organization']->id);
})->group('security');

it('never moves another tab\'s session-scoped writes by opening an organization by address', function (): void {
    ['first' => $first, 'second' => $second, 'secondSlug' => $secondSlug] = twoOrganizations();
    $this->get(route('directory.members'))->assertOk();
    $token = session()->token();

    // Tab 2 opens the second organization by address…
    nextRequest();
    $this->get('/orgs/'.$secondSlug.'/projects')->assertOk();

    // …the session id and CSRF token did not rotate, and the session stayed put…
    expect(session()->token())->toBe($token)
        ->and(session(PlatformAuth::ORG_KEY))->toBe($first['organization']->id);

    // …so tab 1, still on a page that reads the session's organization, acts on the first.
    // (An invitation from it is refused for a platform organization, and refused for the
    // first, not quietly sent from the second.)
    nextRequest();
    $this->get(route('directory.members'))->assertOk();
    expect(app(CurrentUser::class)->organizationId())->toBe($first['organization']->id);

    nextRequest();
    $this->from(route('directory.members'))
        ->post(route('directory.members.invite'), ['email' => 'tab-one@example.test', 'role' => 'member']);
    expect(app(CurrentUser::class)->organizationId())->toBe($first['organization']->id);
    $this->assertDatabaseMissing('invitations', ['email' => 'tab-one@example.test', 'organization_id' => $second['organization']->id]);
})->group('security');

it('lists only the person\'s own organizations at /orgs, with where each one opens', function (): void {
    ['firstSlug' => $firstSlug, 'secondSlug' => $secondSlug] = twoOrganizations();
    provisionAccount('stranger@example.test');

    $this->get('/orgs')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('console/orgs/index')
        ->has('organizations', 2)
        ->where('organizations', fn ($organizations): bool => collect($organizations)->pluck('slug')->sort()->values()->all() === collect([$firstSlug, $secondSlug])->sort()->values()->all())
        ->where('organizations', fn ($organizations): bool => collect($organizations)->every(fn (array $organization): bool => str_ends_with($organization['href'], '/orgs/'.$organization['slug']))));
});

it('opens an organization at its first page', function (): void {
    ['firstSlug' => $firstSlug] = twoOrganizations();

    $this->get('/orgs/'.$firstSlug)->assertRedirect(route('orgs.projects', ['org' => $firstSlug]));
});

it('sends / to the organization this browser used last, remembered by a cookie and nothing more', function (): void {
    // The SaaS shape, where `/dashboard` hands an organization to its console.
    multiTenantDeployment();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    ['firstSlug' => $firstSlug, 'secondSlug' => $secondSlug] = twoOrganizations();
    $app = 'https://cboxid.com';

    // Opening an organization remembers it.
    $this->get($app.'/orgs/'.$secondSlug.'/projects')
        ->assertOk()
        ->assertCookie(LastUsedOrganization::COOKIE, $secondSlug);

    nextRequest();
    $this->get($app.'/')->assertRedirect(route('dashboard'));
    // Opening it by address did not move the session, so `/` goes through its front door.
    nextRequest();
    $this->withCookie(LastUsedOrganization::COOKIE, $secondSlug)
        ->get($app.'/dashboard')
        ->assertRedirect($app.'/orgs/'.$secondSlug);

    // …even when the session was last in the other organization: the cookie is what this
    // browser used last.
    nextRequest();
    $this->get($app.'/orgs/'.$firstSlug.'/projects')->assertOk();
    nextRequest();
    $this->withCookie(LastUsedOrganization::COOKIE, $secondSlug)
        ->get($app.'/dashboard')
        ->assertRedirect($app.'/orgs/'.$secondSlug);

    // A remembered slug the person cannot open authorizes nothing: `/` falls back to the
    // organization the session is in.
    nextRequest();
    $this->withCookie(LastUsedOrganization::COOKIE, 'somebody-elses')
        ->get($app.'/dashboard')
        ->assertRedirect($app.'/orgs/'.$firstSlug.'/projects');
});

it('switches organization on the same page, or that page\'s list for a record', function (): void {
    ['first' => $first, 'second' => $second, 'firstSlug' => $firstSlug, 'secondSlug' => $secondSlug] = twoOrganizations();

    // The same page: Team stays Team.
    $this->post(route('orgs.switch'), [
        'organization' => $second['organization']->id,
        'path' => '/orgs/'.$firstSlug.'/settings/members',
    ])->assertRedirect(route('orgs.settings.members', ['org' => $secondSlug]));

    // A record of the organization being left: its list.
    $this->post(route('orgs.switch'), [
        'organization' => $second['organization']->id,
        'path' => '/orgs/'.$firstSlug.'/projects/'.$first['project']->id,
    ])->assertRedirect(route('orgs.projects', ['org' => $secondSlug]));

    // Nothing to keep (not a console page, or not a path at all): the front door.
    foreach (['/settings', 'https://evil.example/orgs/x', '', '/orgs/'.$firstSlug] as $path) {
        $this->post(route('orgs.switch'), ['organization' => $second['organization']->id, 'path' => $path])
            ->assertRedirect(route('orgs.show', ['org' => $secondSlug]));
    }

    // By slug as well as by id.
    $this->post(route('orgs.switch'), ['organization' => $secondSlug, 'path' => '/orgs/'.$firstSlug.'/settings'])
        ->assertRedirect(route('orgs.settings', ['org' => $secondSlug]));

    // An organization that is not theirs: the picker, and no switch.
    $this->post(route('orgs.switch'), ['organization' => 'not-mine', 'path' => '/orgs/'.$firstSlug.'/settings'])
        ->assertRedirect(route('orgs.index'));
});

it('keeps every v2 organization-console address answering, in the organization used last', function (): void {
    ['firstSlug' => $firstSlug] = twoOrganizations();

    foreach ([
        '/projects' => '/projects',
        '/projects/new' => '/projects/new',
        '/team' => '/settings/members',
        '/keys' => '/settings/api-keys',
        '/keys/workspace' => '/settings/api-keys/organization',
        '/workspace-settings' => '/settings',
        '/environment-domains' => '/environment-domains',
        '/billing' => '/settings/billing',
        '/billing/invoices/INV-1/download' => '/settings/billing/invoices/INV-1/download',
    ] as $old => $new) {
        $this->get($old.'?page=2')->assertStatus(302)->assertRedirect('/orgs/'.$firstSlug.$new.'?page=2');
    }

    // A form an old tab still has open posts its body on to the new address.
    $this->post('/team/invitations', ['email' => 'x@example.test'])
        ->assertStatus(307)
        ->assertRedirect('/orgs/'.$firstSlug.'/settings/members/invitations');
});

it('keeps every v2 address of a person\'s own settings answering at /settings', function (): void {
    ['subjectId' => $subjectId] = provisionAccount();
    signInAsMember($subjectId);

    foreach ([
        '/account' => '/settings',
        '/account/activity' => '/settings/activity',
        '/account/api-keys?client_id=abc&return_to=%2Fdone' => '/settings/api-keys?client_id=abc&return_to=%2Fdone',
        '/accounts' => '/settings/accounts',
        '/accounts/add' => '/settings/accounts/add',
    ] as $old => $new) {
        $this->get($old)->assertStatus(301)->assertRedirect($new);
    }

    $this->post('/account/password', [])->assertStatus(308)->assertRedirect('/settings/password');
    $this->get('/settings')->assertOk();
});

it('serves no Cbox organization console on a customer environment\'s host', function (): void {
    ['subjectId' => $subjectId, 'organization' => $organization, 'environment' => $environment] = provisionAccount();
    signInAsMember($subjectId);
    $slug = orgSlug($organization->id);

    serveOnTestHost($environment);

    // Not served: a 404, or the tenant's own sign-in for a page behind it, and never a
    // way into `/orgs/{org}` from a customer's host.
    foreach (['/orgs', '/orgs/'.$slug, '/orgs/'.$slug.'/projects', '/projects', '/team'] as $path) {
        $response = $this->get($path);

        expect($response->status())->toBeIn([302, 404], $path)
            ->and(str_contains((string) $response->headers->get('Location'), '/orgs'))->toBeFalse($path);
    }
})->group('security');

/*
|--------------------------------------------------------------------------
| ONE REALM: an organization exists once, and its own slug is its address
|--------------------------------------------------------------------------
*/

it('addresses an organization by its own slug and name, in the URL, the picker and the environment console', function (): void {
    ['subjectId' => $subjectId, 'organization' => $organization, 'project' => $project, 'environment' => $environment] = provisionAccount();

    signInAsMember($subjectId);

    expect(orgSlug($organization->id))->toBe($organization->slug)
        ->and(app(EnvironmentConsolePath::class)->for($environment->id))
        ->toBe('orgs/'.$organization->slug.'/projects/'.$project->slug.'/environments/'.$environment->slug);

    $this->get('/orgs/'.$organization->slug.'/projects')->assertOk();

    $this->get('/orgs')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('organizations', 1)
        ->where('organizations.0.slug', $organization->slug)
        ->where('organizations.0.name', $organization->name));
});

it('leaves an organization that is no longer active out of the switcher and off its address, and lists no organization twice', function (): void {
    ['first' => $first, 'second' => $second, 'firstSlug' => $firstSlug, 'secondSlug' => $secondSlug] = twoOrganizations();

    $directory = app(OrganizationDirectory::class);
    $ids = array_map(static fn ($entry): string => $entry->id, $directory->forSubject($first['subjectId']));

    expect($ids)->toHaveCount(2)->and(array_unique($ids))->toHaveCount(2);

    // Closing an organization keeps its memberships; it is still nowhere to switch to.
    DB::table('organizations')->where('id', $second['organization']->id)->update(['status' => 'deleted']);
    $directory->flush();

    expect(array_map(static fn ($entry): string => $entry->slug, $directory->forSubject($first['subjectId'])))->toBe([$firstSlug])
        ->and($directory->find($first['subjectId'], $secondSlug))->toBeNull()
        // Its address is still known, for a link that has to say where something was.
        ->and($directory->addressOf($second['organization']->id))->toMatchArray(['slug' => $secondSlug, 'active' => false]);

    $this->get('/orgs')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('organizations', 1)
        ->where('organizations.0.slug', $firstSlug));
    $this->get('/orgs/'.$secondSlug.'/projects')->assertNotFound();
});

it('keeps the v2 writes of a customer organization\'s own settings answering at /organization', function (): void {
    actingAsRole(MembershipRole::Owner);

    // An old tab's rename and close: a 308, so the method and body go along, marked deprecated.
    $this->patch('/settings', ['name' => 'Acme'])
        ->assertStatus(308)
        ->assertRedirect('/organization')
        ->assertHeader('Deprecation', 'true');
    $this->delete('/settings/organization', ['name' => 'Acme'])
        ->assertStatus(308)
        ->assertRedirect('/organization')
        ->assertHeader('Deprecation', 'true');

    // `/settings` itself is the person's own page now; on the environment's host it shows
    // an administrator where the organization's went, instead of silently being another page.
    $this->get('/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('organizationSettingsMoved', url('/organization')));
    $this->get('/organization')->assertOk();
});
