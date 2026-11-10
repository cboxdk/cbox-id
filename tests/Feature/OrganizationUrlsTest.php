<?php

declare(strict_types=1);

use App\Http\Middleware\BindOrganizationFromPath;
use App\Platform\CurrentUser;
use App\Platform\Organizations\LastUsedOrganization;
use App\Platform\Organizations\OrganizationLinks;
use App\Platform\PlatformAuth;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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
| ONE ORGANIZATION: the platform-root row and the `cbox` organization
|--------------------------------------------------------------------------
*/

function cboxEnvironment(): Environment
{
    return Environment::query()->create([
        'name' => 'Cbox',
        'slug' => 'cbox',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);
}

function cboxOrganization(Environment $cbox, string $slug, string $name): string
{
    $id = Str::lower((string) Str::ulid());

    DB::table('organizations')->insert([
        'id' => $id,
        'environment_id' => $cbox->id,
        'name' => $name,
        'slug' => $slug,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('addresses a linked organization by its Cbox slug and name, in the URL and in the picker', function (): void {
    ['subjectId' => $subjectId, 'organization' => $organization] = provisionAccount();
    $cbox = cboxEnvironment();
    $shared = cboxOrganization($cbox, 'lovelace-labs', 'Lovelace Labs');

    $links = app(OrganizationLinks::class);
    $links->flush();
    $links->link($organization->id, $shared);

    signInAsMember($subjectId);

    expect(orgSlug($organization->id))->toBe('lovelace-labs');

    $this->get('/orgs/lovelace-labs/projects')->assertOk();
    // The platform-root row's own slug is no longer an address: one organization, one URL.
    $this->get('/orgs/'.$organization->slug.'/projects')->assertNotFound();

    $this->get('/orgs')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('organizations.0.slug', 'lovelace-labs')
        ->where('organizations.0.name', 'Lovelace Labs'));
});

/** A user in the `cbox` environment who is an active member of a `cbox` organization. */
function cboxMember(Environment $cbox, string $cboxOrganizationId, string $email): string
{
    $userId = DB::table('users')->where('environment_id', $cbox->id)->where('email', $email)->value('id');

    if (! is_string($userId)) {
        $userId = Str::lower((string) Str::ulid());
        DB::table('users')->insert([
            'id' => $userId, 'environment_id' => $cbox->id, 'email' => $email, 'name' => 'Member',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    DB::table('memberships')->insert([
        'id' => Str::lower((string) Str::ulid()), 'environment_id' => $cbox->id, 'organization_id' => $cboxOrganizationId,
        'user_id' => $userId, 'role' => 'owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $userId;
}

it('pairs organizations by slug once, only where the owner is a member there, and refuses a pair that would give two organizations one address', function (): void {
    ['organization' => $acme] = provisionAccount('a@example.test');
    ['organization' => $other] = provisionAccount('b@example.test');
    ['organization' => $stranger] = provisionAccount('s@example.test');
    ['organization' => $third] = provisionAccount('c@example.test');
    $cbox = cboxEnvironment();
    $sharedAcme = cboxOrganization($cbox, $acme->slug, 'Acme');
    cboxMember($cbox, $sharedAcme, 'A@example.test');
    $sharedOther = cboxOrganization($cbox, 'unrelated', 'Unrelated');
    // The same slug, chosen by a customer who has nothing to do with this one.
    $lookalike = cboxOrganization($cbox, $stranger->slug, 'Somebody else');
    cboxMember($cbox, $lookalike, 'not-the-owner@example.test');

    $links = app(OrganizationLinks::class);
    $links->flush();

    // The dry run names exactly what would be paired, and writes nothing.
    expect($links->autoPairs())->toBe([['organization_id' => $acme->id, 'cbox_organization_id' => $sharedAcme, 'slug' => $acme->slug]]);
    $this->artisan('orgs:link', ['--by-slug' => true, '--dry-run' => true])->assertSuccessful();
    expect(DB::table('organization_links')->count())->toBe(0);

    expect($links->pairBySlug())->toBe(1)
        // Safe to run again: nothing new to pair.
        ->and($links->pairBySlug())->toBe(0)
        ->and(DB::table('organization_links')->where('organization_id', $acme->id)->value('cbox_organization_id'))->toBe($sharedAcme)
        ->and(collect($links->unpaired())->pluck('id')->all())->toContain($other->id, $stranger->id);

    // Each side at most once.
    expect(fn () => $links->link($acme->id, $sharedOther))->toThrow(DomainException::class)
        ->and(fn () => $links->link($other->id, $sharedAcme))->toThrow(DomainException::class);

    // A Cbox slug that is another unlinked organization's own address.
    $clash = cboxOrganization($cbox, $other->slug, 'Clash');
    expect(fn () => $links->link($third->id, $clash))->toThrow(DomainException::class, 'already addresses');

    // The operator's command pairs by hand, and lists what is left.
    $this->artisan('orgs:link', ['platform' => $other->slug, 'cbox' => 'unrelated'])->assertSuccessful();
    $this->artisan('orgs:link', ['--unpaired' => true])->assertSuccessful();
    expect(DB::table('organization_links')->where('organization_id', $other->id)->value('cbox_organization_id'))->toBe($sharedOther);

    // …and undoes a mistake: the organization answers to its own slug again.
    $this->artisan('orgs:unlink', ['platform' => $other->slug])->assertSuccessful();
    expect(DB::table('organization_links')->where('organization_id', $other->id)->exists())->toBeFalse()
        ->and(orgSlug($other->id))->toBe($other->slug);
    $this->artisan('orgs:unlink', ['platform' => $other->slug])->assertFailed();
});

it('links a new organization to its cbox organization when it is created, so its address is the same everywhere', function (): void {
    $cbox = cboxEnvironment();
    // The owner already signs in to Cbox apps.
    $ownerId = Str::lower((string) Str::ulid());
    DB::table('users')->insert([
        'id' => $ownerId, 'environment_id' => $cbox->id, 'email' => 'new-owner@example.test',
        'name' => 'Owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(OrganizationLinks::class)->flush();

    ['subjectId' => $subjectId, 'organization' => $organization] = provisionAccount('new-owner@example.test');

    $cboxOrganization = DB::table('organization_links')->where('organization_id', $organization->id)->value('cbox_organization_id');
    expect($cboxOrganization)->toBeString();

    $row = DB::table('organizations')->where('id', $cboxOrganization)->first();
    expect($row->environment_id)->toBe($cbox->id)
        ->and($row->slug)->toBe($organization->slug)
        ->and($row->name)->toBe($organization->name)
        ->and(DB::table('memberships')->where('organization_id', $cboxOrganization)->where('user_id', $ownerId)->value('role'))->toBe('owner')
        ->and(orgSlug($organization->id))->toBe($row->slug);

    signInAsMember($subjectId);
    $this->get('/orgs/'.$row->slug.'/projects')->assertOk();
});

it('never borrows a stranger\'s cbox organization for a new organization with the same slug', function (): void {
    $cbox = cboxEnvironment();
    $stranger = cboxOrganization($cbox, 'acme', 'Somebody else');
    cboxMember($cbox, $stranger, 'stranger@example.test');
    app(OrganizationLinks::class)->flush();

    ['organization' => $organization] = provisionAccount('fresh-owner@example.test');

    $cboxOrganization = DB::table('organization_links')->where('organization_id', $organization->id)->value('cbox_organization_id');
    $slug = DB::table('organizations')->where('id', $cboxOrganization)->value('slug');

    expect($organization->slug)->toBe('acme')
        ->and($cboxOrganization)->toBeString()->not->toBe($stranger)
        ->and($slug)->toStartWith('acme-')
        ->and(orgSlug($organization->id))->toBe($slug)
        ->and(DB::table('memberships')->where('organization_id', $stranger)->count())->toBe(1);
});

it('never lets an unlinked organization share the address a link claims', function (): void {
    ['organization' => $linked] = provisionAccount('l@example.test');
    ['subjectId' => $subjectId, 'organization' => $unlinked] = provisionAccount('u@example.test');
    $cbox = cboxEnvironment();
    $shared = cboxOrganization($cbox, $unlinked->slug, 'Shared');

    $links = app(OrganizationLinks::class);
    $links->flush();
    // Linked by hand to the OTHER root organization, whose slug differs: the unlinked
    // organization's own slug is now claimed. (`link()` refuses this pair when made in the
    // other order; data written before the rule existed can still look like this.)
    DB::table('organization_links')->insert([
        'id' => Str::lower((string) Str::ulid()), 'organization_id' => $linked->id, 'cbox_organization_id' => $shared,
        'cbox_environment_id' => $cbox->id, 'paired_by' => 'operator', 'created_at' => now(), 'updated_at' => now(),
    ]);

    signInAsMember($subjectId);

    $address = orgSlug($unlinked->id);
    expect($address)->not->toBe($unlinked->slug)->toBe(strtolower($unlinked->id));
    $this->get('/orgs/'.$address.'/projects')->assertOk();
    // The claimed slug is the linked organization's, which this person is not in.
    $this->get('/orgs/'.$unlinked->slug.'/projects')->assertNotFound();
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

    // `/settings` itself is the person's own page; away from a customer environment's host
    // it carries no pointer to the organization's.
    $this->get('/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('organizationSettingsMoved', null));
});
