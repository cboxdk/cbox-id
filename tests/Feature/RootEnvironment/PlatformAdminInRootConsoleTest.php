<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateOperator;
use App\Platform\Impersonation;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| ONE CONSOLE FOR AN OPERATOR ON THE ROOT HOST
|--------------------------------------------------------------------------
|
| The owner, on the root: "why is there still a /platform? it belongs under /admin on the
| root". The root host served the platform environment's console at `/admin` and platform
| admin at `/platform`, each with its own rail — an amber strip, a brand label and a way out
| on one, the environment's areas on the other — and the account menu as the door between
| them. An operator had two consoles on one host.
|
| Now platform admin is the Platform area of `/admin`: the same shell, rail, context switcher,
| ⌘K and page-scope line as every other page there, at `/admin/platform/…`. This holds that
| shape, who may see it (operators, on the root's own host, and nobody else — a 404 for
| everyone else), every old address, and the support flow that starts from it.
*/

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    multiTenantDeployment('cboxid.com');
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
});

/** An operator on the root, with a workspace on the install to look at. @return array{organization: \Cbox\Id\Organization\Models\Organization, subjectId: string} */
function rootPlatformWorld(): array
{
    $account = provisionAccount('owner@acme.example');
    actAsOperator('ops@platform.test');

    return ['organization' => $account['organization'], 'subjectId' => $account['subjectId']];
}

/** Every platform-admin route — the operator gate's own, and the queue monitor's under it. @return list<Route> */
function platformAdminRoutes(): array
{
    return array_values(array_filter(
        app('router')->getRoutes()->getRoutes(),
        static fn (Route $route): bool => in_array(AuthenticateOperator::class, $route->gatherMiddleware(), true),
    ));
}

it('draws platform admin as the Platform area of the platform environment\'s console', function (): void {
    rootPlatformWorld();

    $shell = (array) $this->get('https://cboxid.com/admin/platform/workspaces')->assertOk()->inertiaProps('shell');
    $keys = collect($shell['areas'])->pluck('key');

    // The environment console's own shell — its altitude, its areas, its context switcher —
    // with the Platform area first and lit.
    expect($shell['altitude'])->toBe('environment')
        ->and($keys->first())->toBe('platform')
        ->and($keys)->toContain('Home', 'Authentication', 'Users & orgs', 'Settings')
        ->and($shell['activeArea'])->toBe('platform')
        ->and(collect($shell['areas'][0]['pages'])->pluck('label')->all())
        ->toBe(['Workspaces', 'Environments', 'Organization tree', 'Operators', 'Usage', 'Queues', 'Search'])
        ->and($shell['brandHref'])->toBe('https://cboxid.com/admin/platform/workspaces')
        ->and($shell['context']['workspaces'][0]['label'])->toBe('Platform admin')
        ->and($shell['context']['projects'][0]['environments'][0]['current'])->toBeTrue()
        // In the rail, so not in the account menu as well.
        ->and($shell['platformHref'])->toBeNull()
        ->and($shell['platformEnvironmentHref'])->toBeNull()
        ->and($shell['section'])->toBe('Platform')
        // The amber strip's message, as the page-scope line every page carries.
        ->and($shell['actsOn'])->toMatchArray(['kind' => 'platform', 'label' => 'The whole deployment', 'critical' => true])
        ->and($shell)->not->toHaveKey('platformMode');

    // …and every environment page of that console carries the same Platform area, unlit.
    $environment = (array) $this->get('https://cboxid.com/admin/social-sign-in')->assertOk()->inertiaProps('shell');

    expect(collect($environment['areas'])->pluck('key')->first())->toBe('platform')
        ->and($environment['activeArea'])->toBe('Authentication')
        ->and($environment['platformHref'])->toBeNull()
        ->and($environment['actsOn']['kind'])->toBe('environment');
});

it('keeps the Platform area lit on every page of it, detail pages included', function (): void {
    ['organization' => $workspace] = rootPlatformWorld();

    foreach ([
        '/admin/platform/workspaces/'.$workspace->id => 'Workspaces',
        '/admin/platform/environments' => 'Environments',
        '/admin/platform/organizations' => 'Organization tree',
        '/admin/platform/operators' => 'Operators',
        '/admin/platform/usage' => 'Usage',
        '/admin/platform/queues' => 'Queues',
        '/admin/platform/search?q=acme' => 'Search',
    ] as $path => $label) {
        $shell = (array) $this->get('https://cboxid.com'.$path)->assertOk()->inertiaProps('shell');
        $lit = collect($shell['areas'][0]['pages'])->firstWhere('active', true);

        expect($shell['activeArea'])->toBe('platform', $path)
            ->and($lit['label'] ?? null)->toBe($label, $path);
    }
});

it('leaves platform admin off the root host\'s workspace console, and links it from the account menu', function (): void {
    rootPlatformWorld();

    $shell = (array) $this->get('https://cboxid.com/account')->assertOk()->inertiaProps('shell');
    $routes = collect($shell['areas'])->flatMap(static fn (array $area): array => array_column($area['pages'], 'route'));

    expect($routes->filter(static fn (string $route): bool => str_starts_with($route, 'platform.'))->all())->toBe([])
        ->and($shell['platformHref'])->toBe('https://cboxid.com/admin/platform/workspaces')
        ->and($shell['platformEnvironmentHref'])->toBe('https://cboxid.com/admin');
});

it('answers every platform-admin route with a 404 for a signed-in person who does not run the deployment', function (): void {
    ['subjectId' => $owner] = rootPlatformWorld();

    // The workspace's owner — signed in on the root, administering a workspace, and no
    // operator.
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($owner, 'owner@acme.example'));
    signInAsSubject($owner);

    $routes = platformAdminRoutes();
    $served = [];

    foreach ($routes as $route) {
        $uri = '/'.preg_replace('/\{[^}]+\}/', '01JPLATFORMPROBE00000000000', $route->uri());
        $method = collect($route->methods())->first(static fn (string $verb): bool => $verb !== 'HEAD');
        $status = $this->call((string) $method, 'https://cboxid.com'.$uri)->status();

        if ($status !== 404) {
            $served[] = "{$method} {$uri} → {$status}";
        }
    }

    expect(count($routes))->toBeGreaterThan(40)
        ->and($served)->toBe([], implode("\n", $served));

    // …and the rail of the console they do have says nothing about it.
    $shell = (array) $this->get('https://cboxid.com/account')->assertOk()->inertiaProps('shell');

    expect($shell['platformHref'])->toBeNull()
        ->and(json_encode($shell))->not->toContain('/admin/platform');
})->group('security');

it('sends a signed-out visitor to the root\'s sign-in, and answers nothing on any other host', function (): void {
    platformRootEnvironment();
    installedDeployment();

    Environment::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);

    $this->get('https://cboxid.com/admin/platform/workspaces')->assertRedirect(route('login'));

    // A tenant's host, and a name that merely resolves to the root: no staff pages, not
    // even a sign-in redirect that would say there are some.
    foreach (['acme.cboxid.com', 'nope.cboxid.com'] as $host) {
        foreach (platformAdminRoutes() as $route) {
            $uri = '/'.preg_replace('/\{[^}]+\}/', '01JPLATFORMPROBE00000000000', $route->uri());
            $method = collect($route->methods())->first(static fn (string $verb): bool => $verb !== 'HEAD');

            expect($this->call((string) $method, 'https://'.$host.$uri)->status())->toBe(404, "{$method} {$host}{$uri}");
        }

        $this->get('https://'.$host.'/platform/workspaces')->assertNotFound();
    }
})->group('security');

it('runs a support session from the organization tree end to end, on the root host', function (): void {
    rootPlatformWorld();
    [$organization, $member] = impersonationMember();

    // The organization tree's detail page offers the way in…
    $members = (array) $this->get('https://cboxid.com/admin/platform/organizations/'.$organization->id)
        ->assertOk()->inertiaProps('members');
    $href = collect($members)->firstWhere('userId', $member->id)['impersonateHref'] ?? null;

    expect($href)->toBe('https://cboxid.com/admin/platform/impersonate/'.$member->id);

    // …which lands on the member's own console, where the banner and its Exit render —
    // not on platform admin, which would answer the member's session with a 404 and no
    // chrome at all.
    $this->post((string) $href, ['organization' => $organization->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));
    nextRequest();

    $page = $this->get('https://cboxid.com/dashboard')->assertOk();

    expect($page->inertiaProps('impersonation.email'))->toBe('member@acme.test')
        ->and($page->inertiaProps('shell'))->not->toBeNull();

    // While it lasts, nothing of platform admin or the platform environment answers.
    $this->get('https://cboxid.com/admin/platform/organizations')->assertNotFound();
    $this->get('https://cboxid.com/admin')->assertNotFound();

    // Exit returns the operator to where they started — inside the one console.
    $this->post('https://cboxid.com/impersonation/exit')->assertRedirect(route('platform.organizations'));
    nextRequest();

    $shell = (array) $this->get('https://cboxid.com/admin/platform/organizations')->assertOk()->inertiaProps('shell');

    expect(session(Impersonation::SESSION_KEY))->toBeNull()
        ->and($shell['altitude'])->toBe('environment')
        ->and($shell['activeArea'])->toBe('platform');
})->group('security');

it('ends a support session that outlives its window back in platform admin', function (): void {
    rootPlatformWorld();
    [$organization, $member] = impersonationMember();

    $this->post('https://cboxid.com/admin/platform/impersonate/'.$member->id, ['organization' => $organization->id, 'reason' => IMPERSONATION_REASON]);
    nextRequest();

    $this->travel(Impersonation::MAX_MINUTES + 1)->minutes();

    // Back to platform admin's own address — inside the one console — with the reason said.
    // (Whether the operator's own session is still fresh after half an hour away is the
    // session policy's question, held in ImpersonationTest; this is about where it lands.)
    $this->get('https://cboxid.com/dashboard')
        ->assertRedirect('https://cboxid.com/admin/platform/organizations')
        ->assertSessionHas('status', 'Impersonation session expired.');

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
})->group('security');

it('runs a support session from the platform environment\'s own Users page, landing where the banner is', function (): void {
    rootPlatformWorld();
    [$organization, $member] = impersonationMember();

    // The same console's other way in — a user of the platform environment, from its own
    // Users page. It used to land on the environment console's home, which answers the
    // member's session with a 404: no chrome, no banner, no Exit.
    $this->post('https://cboxid.com/admin/impersonate/'.$member->id, ['organization' => $organization->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));
    nextRequest();

    expect($this->get('https://cboxid.com/dashboard')->assertOk()->inertiaProps('impersonation.email'))->toBe('member@acme.test');

    $this->post('https://cboxid.com/impersonation/exit')->assertRedirect(route('environment.home'));
    nextRequest();

    // Back in the console they started from — signed in as themselves, not at the door: an
    // operator holds no workspace membership, and Exit used to restore only those who did.
    $this->get('https://cboxid.com/admin')->assertOk();
})->group('security');
