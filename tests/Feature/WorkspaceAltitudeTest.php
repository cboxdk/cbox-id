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

it('lands a workspace member with one environment straight in that environment\'s console', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = aWorkspace();
    signInAsMember($subjectId);

    // `/dashboard` is where every sign-in lands. Through the same signed handoff Projects ›
    // Open uses — not a link to the tenant host, which would meet a door with no session.
    $this->get('https://cboxid.com/dashboard')
        ->assertRedirect(route('environment.open', $environment->id));
});

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

    expect($labels)->toBe(['Workspace', 'Team sign-in', 'Logs', 'My account'])
        ->and($shell['altitude'])->toBe('workspace')
        ->and($shell['brandHref'])->toBe(route('projects'))
        ->and($shell['notice'])->toBeNull();
});

it('says what a hidden page is when a workspace reaches it by URL, and does not refuse it', function (): void {
    ['subjectId' => $subjectId] = aWorkspace();
    signInAsMember($subjectId);

    // Hidden, not redirected: an app a workspace registered in Cbox's environment before
    // this change is still reachable, so it can still be deleted.
    $shell = (array) $this->get('https://cboxid.com/roles')->assertOk()->inertiaProps('shell');

    expect($shell['notice'])->toBeArray()
        // …and the rail does not light an area above it: the page is not Workspace's.
        ->and($shell['activeArea'])->toBeNull()
        ->and($shell['notice']['href'])->toBe(route('projects'))
        ->and($shell['notice']['message'])->toContain('not your product');
});

it('keeps the full console for the people who run the deployment', function (): void {
    aWorkspace();

    $operator = app(PlatformOperators::class)->create('op@platform.test', 'a-strong-operator-pass', 'Operator');
    signInAsSubject((string) $operator->subject_id);

    expect(app(ConsoleScope::class)->atWorkspaceAltitude())->toBeFalse();
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

    // To Projects, not to the landing: the landing would hand them straight back here.
    expect($shell['workspace'])->toBe([
        'name' => $workspace->name,
        'href' => 'https://cboxid.com/projects',
    ])->and($shell['altitude'])->toBe('environment');
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
