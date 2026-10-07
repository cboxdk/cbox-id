<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
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

uses(RefreshDatabase::class);

/**
 * THE TOPBAR'S `Workspace ▾ / Project ▾ / Environment ▾`, as the server decides it.
 *
 * Every row in that control is a door: a workspace to switch into, an environment to open
 * through the signed handoff. So what these assert is the authorization a menu implies —
 * that it lists exactly what the person may open, never another customer's estate, and
 * that each link leads where its label says.
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

/** The shell's context for a URL on the workspace host. */
function workspaceContext(string $route): array
{
    return (array) test()->get('https://cboxid.com'.route($route, absolute: false))->assertOk()->inertiaProps('shell.context');
}

/** @return list<array<string, mixed>> every environment in a context, flattened */
function contextEnvironments(array $context): array
{
    return collect($context['projects'])->pluck('environments')->flatten(1)->values()->all();
}

it('lists the workspace, its project and the environments its owner may open — and no other customer\'s', function (): void {
    $estate = contextEstate();
    signInAsMember($estate['subjectId']);

    $context = workspaceContext('projects');

    expect($context['noun'])->toBe('Workspace')
        ->and($context['workspaces'])->toHaveCount(1)
        ->and($context['workspaces'][0])->toMatchArray([
            'id' => $estate['organization']->id,
            'label' => 'Acme',
            'current' => true,
        ])
        // Switching is a POST on this host — the session lives here.
        ->and($context['switchUrl'])->toEndWith('/organization/switch')
        ->and($context['projects'])->toHaveCount(1)
        ->and($context['projects'][0]['id'])->toBe($estate['project']->id)
        // On the workspace's own console no environment is current: this IS the workspace.
        ->and($context['projects'][0]['current'])->toBeFalse();

    $environments = contextEnvironments($context);

    expect(array_column($environments, 'id'))->toBe([$estate['production']->id, $estate['sandbox']->id])
        ->and(array_column($environments, 'type'))->toBe(['production', 'sandbox'])
        ->and(array_column($environments, 'id'))->not->toContain($estate['foreign']->id);

    // Each one through the door that checks access and mints the handoff — and from
    // Projects, which no environment console has, with no page to land on.
    foreach ($environments as $environment) {
        expect($environment['href'])->toBe('https://cboxid.com/open/'.$environment['id']);
    }
});

it('names every workspace the person belongs to, and only those', function (): void {
    $estate = contextEstate();

    // A member of Globex too — and of nothing else.
    $globexId = app(PlatformRoot::class)->run(
        fn () => app(Organizations::class)->bySlug('globex')?->id,
    );
    expect($globexId)->toBeString();
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->add($globexId, $estate['subjectId'], MembershipRole::Developer));

    signInAsMember($estate['subjectId']);

    $context = workspaceContext('projects');

    expect(collect($context['workspaces'])->pluck('label')->sort()->values()->all())->toBe(['Acme', 'Globex'])
        ->and(collect($context['workspaces'])->firstWhere('current', true)['label'])->toBe('Acme')
        ->and(collect($context['workspaces'])->firstWhere('label', 'Globex')['caption'])->toBe(MembershipRole::Developer->label())
        // Belonging to Globex does not put Globex's environments in ACME's menus: those
        // are one switch away, answered by Globex's own access rules when it is current.
        ->and(array_column(contextEnvironments($context), 'id'))->not->toContain($estate['foreign']->id);
});

it('carries the page being read into the environment it opens', function (): void {
    $estate = contextEstate();
    signInAsMember($estate['subjectId']);

    // Workspace › Keys is the same capability as an environment's Developers › API keys, so
    // opening production from here opens production's API keys.
    $environments = contextEnvironments(workspaceContext('keys'));

    expect($environments[0]['href'])->toBe('https://cboxid.com/open/'.$estate['production']->id.'?to=%2Fadmin%2Fkeys');
});

it('lists only the environments a scoped member was granted', function (): void {
    $estate = contextEstate();

    [, $developerId] = addMember($estate['organization']->id, MembershipRole::Developer, 'scoped@context.example');
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->setEnvironmentAccess(
        $estate['organization']->id,
        $developerId,
        false,
        [$estate['sandbox']->id],
    ));

    signInAsMember($developerId);

    expect(array_column(contextEnvironments(workspaceContext('projects')), 'id'))->toBe([$estate['sandbox']->id]);
});

it('offers no environment to a member who may not administer one', function (): void {
    $estate = contextEstate();

    // A Viewer reaches environments but is never handed an admin token for one —
    // `/open/{environment}` answers 403 — so the menu must not offer the door.
    [, $viewerId] = addMember($estate['organization']->id, MembershipRole::Viewer, 'viewer@context.example');
    signInAsMember($viewerId);

    $context = workspaceContext('projects');

    expect($context['projects'])->toBe([])
        ->and($context['workspaces'][0]['label'])->toBe('Acme');
});

it('gives the environment console every environment it may switch to, landing on the same page', function (): void {
    $estate = contextEstate();

    serveOnTestHost($estate['production']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($estate['production']->id));
    actAsEnvironmentAdmin($estate['subjectId'], $estate['production']->id);

    // It used to be sent `organizations: []` and `environments: []` — a back arrow and the
    // environment's name, and nothing to switch to.
    $context = (array) $this->get(route('environment.users'))->assertOk()->inertiaProps('shell.context');

    expect($context['noun'])->toBe('Workspace')
        ->and($context['switchUrl'])->toBeNull()
        ->and($context['workspaces'][0])->toMatchArray([
            'label' => 'Acme',
            'current' => true,
            'openHref' => 'https://cboxid.com/projects',
        ])
        ->and($context['projects'])->toHaveCount(1)
        ->and($context['projects'][0]['current'])->toBeTrue();

    $environments = collect(contextEnvironments($context))->keyBy('id');

    expect($environments->keys()->all())->toBe([$estate['production']->id, $estate['sandbox']->id])
        ->and($environments[$estate['production']->id]['current'])->toBeTrue()
        ->and($environments[$estate['sandbox']->id]['current'])->toBeFalse()
        // Back through the WORKSPACE host's door — this host has no session that could
        // mint a handoff — carrying the page being read.
        ->and($environments[$estate['sandbox']->id]['href'])
        ->toBe('https://cboxid.com/open/'.$estate['sandbox']->id.'?to=%2Fadmin%2Fusers');
});

it('lands a detail page on its list, and the home page on the home page', function (): void {
    $estate = contextEstate();

    serveOnTestHost($estate['production']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($estate['production']->id));
    actAsEnvironmentAdmin($estate['subjectId'], $estate['production']->id);

    // An organization of THIS environment has no counterpart in the next one, so its page
    // lands on the list it came from rather than on a 404.
    $tenant = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co-context'));

    $detail = contextEnvironments((array) $this->get(route('environment.organizations.show', $tenant->id))
        ->assertOk()
        ->inertiaProps('shell.context'));

    expect(collect($detail)->firstWhere('id', $estate['sandbox']->id)['href'])
        ->toEndWith('?to=%2Fadmin%2Forganizations');

    $home = contextEnvironments((array) $this->get(route('environment.home'))->assertOk()->inertiaProps('shell.context'));

    expect(collect($home)->firstWhere('id', $estate['sandbox']->id)['href'])
        ->toBe('https://cboxid.com/open/'.$estate['sandbox']->id);
});

it('calls an organization that owns no projects an organization, with nothing below it', function (): void {
    // A single-tenant install's organization: the console IS its product, so there is no
    // project or environment above it to switch between.
    $subject = app(Subjects::class)->create('plain@acme.test', 'Plain Admin', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Plain Co', 'plain-co-context'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $context = (array) $this->get(route('dashboard'))->assertOk()->inertiaProps('shell.context');

    expect($context['noun'])->toBe('Organization')
        ->and($context['workspaces'][0])->toMatchArray(['label' => 'Plain Co', 'current' => true])
        ->and($context['projects'])->toBe([]);
});
