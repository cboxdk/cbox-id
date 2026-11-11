<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateOperator;
use App\Platform\Impersonation;
use App\Platform\NativeProduct\NativeProductMembers;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Organization\Contracts\Memberships;
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
| ⌘K and page-scope line as every other page there, at `/admin/…`. This holds that
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

    $response = $this->get('https://cboxid.com/admin/organizations')->assertOk();
    $shell = consoleRail($response);
    $header = (array) $response->inertiaProps('shell');
    $keys = collect($shell['areas'])->pluck('key');

    // The environment console's own rail, with the Platform area first and lit.
    expect($keys->first())->toBe('platform')
        ->and($keys)->toContain('Home', 'Authentication', 'Users & orgs', 'Settings')
        ->and($shell['activeArea'])->toBe('platform')
        ->and(collect($shell['areas'][0]['pages'])->pluck('label')->all())
        ->toBe(['Organizations', 'Environments', 'Organization tree', 'Operators', 'Usage', 'Queues', 'Search'])
        // Platform admin acts on no one organization: the header names none.
        ->and($header['context']['levels'])->toBe([])
        // In the rail, so not in the palette as well.
        ->and(collect($header['palette']['actions'])->pluck('id'))->not->toContain('platform.admin')
        ->and($shell['section'])->toBe('Platform')
        // The amber strip's message, over the page.
        ->and($header['banner']['kind'])->toBe('notice')
        ->and($header['banner']['text'])->toContain('The whole deployment');

    // …and every environment page of that console carries the same Platform area, unlit.
    $response = $this->get('https://cboxid.com/admin/environment/social-sign-in')->assertOk();
    $environment = consoleRail($response);

    expect(collect($environment['areas'])->pluck('key')->first())->toBe('platform')
        ->and($environment['activeArea'])->toBe('Authentication')
        ->and((string) $response->inertiaProps('shell.banner.text'))->toContain('every organization owner and every operator');
});

it('keeps the Platform area lit on every page of it, detail pages included', function (): void {
    ['organization' => $workspace] = rootPlatformWorld();

    foreach ([
        '/admin/organizations/'.$workspace->id => 'Organizations',
        '/admin/environments' => 'Environments',
        '/admin/environments/root/organizations' => 'Organization tree',
        '/admin/operators' => 'Operators',
        '/admin/usage' => 'Usage',
        '/admin/queues' => 'Queues',
        '/admin/search?q=acme' => 'Search',
    ] as $path => $label) {
        $shell = consoleRail($this->get('https://cboxid.com'.$path)->assertOk());
        $lit = collect($shell['areas'][0]['pages'])->firstWhere('active', true);

        expect($shell['activeArea'])->toBe('platform', $path)
            ->and($lit['label'] ?? null)->toBe($label, $path);
    }
});

it('leaves platform admin off the root host\'s organization console, and offers it in the palette', function (): void {
    rootPlatformWorld();

    $response = $this->get('https://cboxid.com/settings')->assertOk();
    $routes = collect(consoleRail($response)['areas'])->flatMap(static fn (array $area): array => array_column($area['pages'], 'route'));
    $actions = collect($response->inertiaProps('shell.palette.actions'))->pluck('url', 'id');

    expect($routes->filter(static fn (string $route): bool => str_starts_with($route, 'platform.'))->all())->toBe([])
        ->and($actions['platform.admin'] ?? null)->toBe('https://cboxid.com/admin/organizations')
        ->and($actions['platform.environment'] ?? null)->toBe('https://cboxid.com/admin/environment');
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
    $shell = (array) $this->get('https://cboxid.com/settings')->assertOk()->inertiaProps('shell');

    expect(json_encode($shell))->not->toContain('/admin');
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

    $this->get('https://cboxid.com/admin/organizations')->assertRedirect(route('login'));

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
    $members = (array) $this->get('https://cboxid.com/admin/environments/root/organizations/'.$organization->id)
        ->assertOk()->inertiaProps('members');
    $href = collect($members)->firstWhere('userId', $member->id)['impersonateHref'] ?? null;

    expect($href)->toBe('https://cboxid.com/admin/environments/root/impersonate/usr_'.$member->id);

    // …which lands on the member's own console, where the banner and its Exit render —
    // not on platform admin, which would answer the member's session with a 404 and no
    // chrome at all.
    $this->post((string) $href, ['organization' => $organization->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));
    nextRequest();

    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('account'));
    $page = $this->get(route('account'))->assertOk();

    expect($page->inertiaProps('impersonation.email'))->toBe('member@acme.test')
        ->and($page->inertiaProps('shell'))->not->toBeNull();

    // While it lasts, nothing of platform admin or the platform environment answers.
    $this->get('https://cboxid.com/admin/environments/root/organizations')->assertNotFound();
    $this->get('https://cboxid.com/admin/environment')->assertNotFound();

    // Exit returns the operator to where they started — inside the one console.
    $this->post('https://cboxid.com/impersonation/exit')->assertRedirect(route('platform.organizations'));
    nextRequest();

    $shell = consoleRail($this->get('https://cboxid.com/admin/environments/root/organizations')->assertOk());

    expect(session(Impersonation::SESSION_KEY))->toBeNull()
        ->and($shell['activeArea'])->toBe('platform');
})->group('security');

it('lands a support session on the targets current native access and honors revocation', function (): void {
    rootPlatformWorld();
    [$organization, $subject] = impersonationMember();
    $member = app(PlatformRoot::class)->run(fn () => app(Memberships::class)->of($organization->id, $subject->id));
    grantNativeProductRoles($member, ['viewer']);
    $this->post('https://cboxid.com/admin/environments/root/impersonate/'.$subject->id, [
        'organization' => $organization->id, 'reason' => IMPERSONATION_REASON,
    ])->assertRedirect(route('dashboard'));
    nextRequest();
    useOrganizationInUrls($organization->id);
    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('orgs.projects'));
    expect($this->get(route('orgs.projects'))->assertOk()->inertiaProps('impersonation.email'))->toBe($subject->email);

    $roles = app(NativeProductMembers::class);
    $roles->replace($member, [], $roles->access($member)['roles_version'], AuditActor::system());
    nextRequest();
    $this->get(route('orgs.projects'))->assertForbidden();
    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('account'));
    expect($this->get(route('account'))->assertOk()->inertiaProps('impersonation.email'))->toBe($subject->email);
})->group('security');

it('ends a support session that outlives its window back in platform admin', function (): void {
    rootPlatformWorld();
    [$organization, $member] = impersonationMember();

    $this->post('https://cboxid.com/admin/environments/root/impersonate/'.$member->id, ['organization' => $organization->id, 'reason' => IMPERSONATION_REASON]);
    nextRequest();

    $this->travel(Impersonation::MAX_MINUTES + 1)->minutes();

    // Back to platform admin's own address — inside the one console — with the reason said.
    // (Whether the operator's own session is still fresh after half an hour away is the
    // session policy's question, held in ImpersonationTest; this is about where it lands.)
    $this->get('https://cboxid.com/dashboard')
        ->assertRedirect('https://cboxid.com/admin/environments/root/organizations')
        ->assertSessionHas('status', 'Impersonation session expired.');

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
})->group('security');

it('ends an outlived support session on an environment console page too', function (): void {
    rootPlatformWorld();
    [$organization, $member] = impersonationMember();

    // Started in place on the root's environment console: the marker lives in the app host's
    // own session, the one every environment console on this host reads.
    $this->post('https://cboxid.com/admin/environment/impersonate/'.$member->id, ['organization' => $organization->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect('https://cboxid.com/dashboard');
    nextRequest();

    // Backdated rather than travelled to, so the operator's own session is still fresh.
    $marker = session(Impersonation::SESSION_KEY);
    $marker['started_at'] = now()->subMinutes(Impersonation::MAX_MINUTES + 1)->getTimestamp();
    session([Impersonation::SESSION_KEY => $marker]);
    session()->save();

    $this->get('https://cboxid.com/admin/environment/users')
        ->assertRedirect('https://cboxid.com/admin/environment')
        ->assertSessionHas('status', 'Impersonation session expired.');

    expect(session(Impersonation::SESSION_KEY))->toBeNull();
})->group('security');

it('runs a support session from the platform environment\'s own Users page, landing where the banner is', function (): void {
    rootPlatformWorld();
    [$organization, $member] = impersonationMember();

    // The same console's other way in — a user of the platform environment, from its own
    // Users page. It used to land on the environment console's home, which answers the
    // member's session with a 404: no chrome, no banner, no Exit.
    $this->post('https://cboxid.com/admin/environment/impersonate/'.$member->id, ['organization' => $organization->id, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect('https://cboxid.com/dashboard');
    nextRequest();

    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('account'));
    expect($this->get(route('account'))->assertOk()->inertiaProps('impersonation.email'))->toBe('member@acme.test');

    $this->post('https://cboxid.com/impersonation/exit')->assertRedirect(route('environment.home'));
    nextRequest();

    // Back in the console they started from — signed in as themselves, not at the door: an
    // operator holds no workspace membership, and Exit used to restore only those who did.
    $this->get('https://cboxid.com/admin/environment')->assertOk();
})->group('security');
