<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A WORKSPACE'S OWN CONSOLE, and where its people land.
 *
 * A customer that owns identity providers signs in at the platform root, where the
 * organization they administer is their Cbox workspace — its record in Cbox's own
 * environment. The console showed them that record's full end-user administration (roles,
 * apps, webhooks, a token vault…) as though it were their product, while the product sat in
 * an environment console on another host with no way back.
 *
 * The SaaS shape: the workspace console at cboxid.com, each environment on its own host.
 */
function aWorkspace(): array
{
    multiTenantDeployment();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    return provisionAccount('owner@workspace.example');
}

/** The rail a request was drawn with, as area key => that area's page routes. */
function railOf(string $url): array
{
    $shell = (array) test()->get($url)->assertOk()->inertiaProps('shell');

    return collect($shell['areas'])
        ->mapWithKeys(fn (array $area): array => [$area['key'] => array_column($area['pages'], 'route')])
        ->all();
}

it('lands a workspace member with one environment on Projects, not inside that environment\'s console', function (): void {
    ['subjectId' => $subjectId] = aWorkspace();
    signInAsMember($subjectId);

    // `/dashboard` is where every sign-in lands. It used to mint a handoff into the one
    // environment, so every sign-in at the account host — Google included — dropped the
    // person into a tenant console they had not asked to open. The console opens when
    // somebody asks for it: Projects › Open console, the switcher, or a sign-in that
    // started at that console's own /admin.
    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('projects'));
})->group('security');

it('lands a workspace with several environments on Projects, where each has its own console', function (): void {
    ['subjectId' => $subjectId, 'project' => $project] = aWorkspace();

    Environment::query()->create([
        'name' => 'Staging', 'slug' => 'workspace-staging', 'status' => EnvironmentStatus::Active,
        'project_id' => $project->id, 'is_default' => false,
    ]);

    signInAsMember($subjectId);

    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('projects'));
});

it('does not count a suspended environment as somewhere to work', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = aWorkspace();

    $environment->forceFill(['status' => EnvironmentStatus::Suspended])->save();
    signInAsMember($subjectId);

    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('projects'));
});

it('never hands somebody who may not administer environments to the handoff', function (): void {
    ['organization' => $workspace] = aWorkspace();
    [, $viewerId] = addMember($workspace->id, MembershipRole::Viewer, 'viewer@workspace.example');

    signInAsMember($viewerId);

    // A Viewer reaches the one environment but may not administer it, and the handoff
    // would refuse them — so the landing must not send them there to be refused.
    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('projects'));
});

it('shrinks a workspace console to the workspace, its team\'s sign-in, its log and the person', function (): void {
    ['subjectId' => $subjectId] = aWorkspace();
    signInAsMember($subjectId);

    $rail = railOf('https://cboxid.com/projects');

    expect(array_keys($rail))->toBe(['identity-platform', 'authentication', 'audit', 'account'])
        // Single sign-on and sign-in rules for the workspace's OWN team — not social
        // sign-in or directory sync, which are for the people signing in to a product.
        ->and($rail['authentication'])->toBe(['connections', 'auth-policy'])
        ->and($rail['audit'])->toBe(['audit'])
        ->and($rail['identity-platform'])->toContain('projects', 'members', 'keys', 'organization-settings');

    $shell = (array) $this->get('https://cboxid.com/projects')->inertiaProps('shell');
    $labels = array_column($shell['areas'], 'label');

    expect($labels)->toBe(['Workspace', 'Team sign-in', 'Audit log', 'My account'])
        ->and($shell['altitude'])->toBe('workspace')
        ->and($shell['brandHref'])->toBe(route('projects'))
        ->and($shell['notice'])->toBeNull();
});

it('answers a page an environment owns, reached by URL on the root, with where it is set', function (): void {
    ['subjectId' => $subjectId] = aWorkspace();
    signInAsMember($subjectId);

    // Not the workspace's Roles page, which configured the workspace's own record in Cbox's
    // environment while looking like the product's: the same URL now says roles are set in
    // an environment's console, and offers the same page in each environment they may open.
    $page = $this->get('https://cboxid.com/roles')->assertOk();

    expect($page->inertiaPage()['component'])->toBe('console/configured-in-environment')
        ->and($page->inertiaProps('label'))->toBe('Roles')
        ->and($page->inertiaProps('samePage'))->toBeTrue()
        // …and the switcher's rows carry that page along, so one click lands on it.
        ->and(json_encode($page->inertiaProps('shell.context.projects')))->toContain(rawurlencode('/admin/roles'));

    // The same for Social login, Branding and Sign-in methods — the pages that looked most
    // like the platform's own sign-in.
    foreach (['/social-sign-in', '/branding', '/sign-in-methods'] as $path) {
        expect($this->get('https://cboxid.com'.$path)->assertOk()->inertiaPage()['component'])->toBe('console/configured-in-environment');
    }

    // A write to one is not there at all: nothing on this host configures the workspace's
    // organization as though it were an environment.
    $this->post('https://cboxid.com/roles', ['name' => 'Sneaky'])->assertNotFound();
});

it('sends an operator from the root\'s organization pages to the platform environment\'s own', function (): void {
    aWorkspace();

    $operator = app(PlatformOperators::class)->create('op@platform.test', 'a-strong-operator-pass', 'Operator');
    signInAsSubject((string) $operator->subject_id);

    // What an operator means by "Social login on cboxid.com" is the platform's sign-in:
    // the root environment's console, at the same page, with the same query.
    $this->get('https://cboxid.com/social-sign-in?organization=x')->assertRedirect('https://cboxid.com/admin/social-sign-in?organization=x');
    $this->get('https://cboxid.com/sign-in-methods')->assertRedirect('https://cboxid.com/admin/sign-in-methods');

    // …and the rail on this host is the workspace console's for them too.
    $rail = railOf('https://cboxid.com/account');

    expect($rail)->not->toHaveKey('developers')
        ->and($rail)->not->toHaveKey('directory');
});

it('keeps the full console on a single-tenant install, where the root environment IS the product', function (): void {
    // The suite's baseline shape. The workspace owns a project there too, and hiding its
    // administration would hide the product itself.
    ['subjectId' => $subjectId] = provisionAccount('owner@selfhosted.example');
    signInAsMember($subjectId);

    $rail = railOf(route('roles'));

    expect($rail)->toHaveKey('directory')
        ->and($rail)->toHaveKey('developers')
        ->and(app(ConsoleScope::class)->atWorkspaceAltitude())->toBeFalse();
});

it('keeps the full console for an organization that is some workspace\'s customer', function (): void {
    // A tenant organization's console IS its product: nothing is withheld.
    [, $organization] = actingAsRole(MembershipRole::Owner);

    expect($organization->id)->not->toBe('')
        ->and(app(ConsoleScope::class)->atWorkspaceAltitude())->toBeFalse();
});

it('gives the environment console a way back to its workspace, on every page', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment, 'organization' => $workspace] = aWorkspace();

    serveOnTestHost($environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    actAsEnvironmentAdmin($subjectId, $environment->id);

    $shell = (array) $this->get(route('environment.home'))->assertOk()->inertiaProps('shell');

    // The context switcher's first crumb names the workspace and goes to its Projects —
    // not to the landing, which would hand them straight back here.
    expect($shell['context']['noun'])->toBe('Workspace')
        ->and($shell['context']['workspaces'])->toHaveCount(1)
        ->and($shell['context']['workspaces'][0])->toMatchArray([
            'id' => $workspace->id,
            'label' => $workspace->name,
            'current' => true,
            'openHref' => 'https://cboxid.com/projects',
        ])
        // There is no POST from here: the session that holds a workspace is on that host.
        ->and($shell['context']['switchUrl'])->toBeNull()
        ->and($shell['altitude'])->toBe('environment');
});

it('sends the environment console\'s account links to the workspace host, where the person is signed in', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = aWorkspace();

    serveOnTestHost($environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    actAsEnvironmentAdmin($subjectId, $environment->id);

    $shell = (array) $this->get(route('environment.home'))->assertOk()->inertiaProps('shell');

    // Relative, these were `/account` and `/accounts` on the TENANT host: pages behind a
    // subject session the administrator does not hold there, which answered by sending
    // them to the tenant's end-user sign-in form.
    expect($shell['accountHref'])->toBe('https://cboxid.com/account')
        ->and($shell['switchUserHref'])->toBe('https://cboxid.com/accounts');

    // …and the relative spelling really is the bounce, which is why it had to go.
    $this->get(route('account'))->assertRedirect();
});
