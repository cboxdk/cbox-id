<?php

declare(strict_types=1);

use App\Platform\Billing\PlanOffers;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\EnvironmentConsolePath;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\Project;
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
    $shell = consoleRail(test()->get($url)->assertOk());

    return collect($shell['areas'])
        ->mapWithKeys(fn (array $area): array => [$area['key'] => array_column($area['pages'], 'route')])
        ->all();
}

it('lands an organization member with one environment on its home, not inside that environment\'s console', function (): void {
    ['subjectId' => $subjectId] = aWorkspace();
    signInAsMember($subjectId);

    // `/dashboard` is where every sign-in lands. It used to mint a handoff into the one
    // environment, so every sign-in at the account host — Google included — dropped the
    // person into a tenant console they had not asked to open. The console opens when
    // somebody asks for it: Identity platforms › Open console, the switcher, or a sign-in
    // that started at that console's own /admin. Since 2026-11-25 the landing is the
    // organization's home, its people first, rather than the list of identity platforms.
    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('orgs.overview'));
})->group('security');

it('lands an organization with several environments on its home, where each has its own console', function (): void {
    ['subjectId' => $subjectId, 'project' => $project] = aWorkspace();

    Environment::query()->create([
        'name' => 'Staging', 'slug' => 'workspace-staging', 'status' => EnvironmentStatus::Active,
        'project_id' => $project->id, 'is_default' => false,
    ]);

    signInAsMember($subjectId);

    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('orgs.overview'));
});

it('does not count a suspended environment as somewhere to work', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = aWorkspace();

    $environment->forceFill(['status' => EnvironmentStatus::Suspended])->save();
    signInAsMember($subjectId);

    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('orgs.overview'));
});

it('never hands somebody who may not administer environments to the handoff', function (): void {
    ['organization' => $workspace] = aWorkspace();
    [, $viewerId] = addMember($workspace->id, MembershipRole::Viewer, 'viewer@workspace.example');

    signInAsMember($viewerId);

    // Shared Viewer grants no native app access; their own account remains available.
    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('account'));
});

it('shrinks an organization console to the organization, its team\'s sign-in, its log and the person', function (): void {
    ['subjectId' => $subjectId] = aWorkspace();
    signInAsMember($subjectId);

    $rail = railOf('https://cboxid.com'.orgPath('/projects'));

    expect(array_keys($rail))->toBe(['identity-platform', 'authentication', 'audit', 'account'])
        // Single sign-on and sign-in rules for the workspace's OWN team — not social
        // sign-in or directory sync, which are for the people signing in to a product.
        ->and($rail['authentication'])->toBe(['connections', 'auth-policy'])
        ->and($rail['audit'])->toBe(['audit'])
        ->and($rail['identity-platform'])->toContain('orgs.projects', 'orgs.settings.members', 'orgs.settings.api-keys', 'orgs.settings');

    $response = $this->get('https://cboxid.com'.orgPath('/projects'));
    $shell = consoleRail($response);
    $labels = array_column($shell['areas'], 'label');
    $header = (array) $response->inertiaProps('shell');

    // The header stands in the organization alone: no project or environment under it, and
    // no notice over the page.
    expect($labels)->toBe(['Organization', 'Team sign-in', 'Audit log', 'Account'])
        ->and(array_column($header['context']['levels'], 'current', 'key'))->toHaveKey('organization')
        ->and($header['context']['levels'][1]['current'] ?? null)->toBeNull()
        ->and($header)->not->toHaveKey('banner');
});

it('answers a page an environment owns, reached by URL on the root, with where it is set', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = aWorkspace();
    signInAsMember($subjectId);

    // Not the workspace's Roles page, which configured the workspace's own record in Cbox's
    // environment while looking like the product's: the same URL now says roles are set in
    // an environment's console, and offers the same page in each environment they may open.
    $page = $this->get('https://cboxid.com/roles')->assertOk();

    expect($page->inertiaPage()['component'])->toBe('console/configured-in-environment')
        ->and($page->inertiaProps('label'))->toBe('Roles')
        ->and($page->inertiaProps('samePage'))->toBeTrue()
        // …and the switcher's rows carry that page along, so one click lands on it.
        ->and(json_encode($page->inertiaProps('projects'), JSON_UNESCAPED_SLASHES))
        ->toContain('https://cboxid.com/'.app(EnvironmentConsolePath::class)->for($environment->id).'/roles"');

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
    signInAsStrongOperator((string) $operator->subject_id);

    // What an operator means by "Social login on cboxid.com" is the platform's sign-in:
    // the root environment's console, at the same page, with the same query.
    $this->get('https://cboxid.com/social-sign-in?organization=x')->assertRedirect('https://cboxid.com/admin/environment/social-sign-in?organization=x');
    $this->get('https://cboxid.com/sign-in-methods')->assertRedirect('https://cboxid.com/admin/environment/sign-in-methods');

    // …and the rail on this host is the workspace console's for them too.
    $rail = railOf('https://cboxid.com/settings');

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
        ->and(app(ConsoleScope::class)->atOrganizationAltitude())->toBeFalse();
});

it('keeps the full console for an organization that is some organization\'s customer', function (): void {
    // A tenant organization's console IS its product: nothing is withheld.
    [, $organization] = actingAsRole(MembershipRole::Owner);

    expect($organization->id)->not->toBe('')
        ->and(app(ConsoleScope::class)->atOrganizationAltitude())->toBeFalse();
});

it('gives the environment console a way back to its organization, on every page', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment, 'organization' => $workspace] = aWorkspace();

    serveOnTestHost($environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    actAsEnvironmentAdmin($subjectId, $environment->id);

    $shell = (array) $this->get(route('environment.home'))->assertOk()->inertiaProps('shell');
    $organization = $shell['context']['levels'][0];

    // The header's first level names the workspace, and its organizations are one click
    // away; choosing it lands on its page, not on the landing that would hand them back.
    expect($organization['key'])->toBe('organization')
        ->and($organization['label'])->toBe('Organization')
        ->and($organization['current'])->toMatchArray(['id' => $workspace->id, 'name' => $workspace->name])
        ->and($organization['allUrl'])->toBe('/orgs');

    $listed = $this->getJson('https://cboxid.com'.$organization['listUrl'])->assertOk()->json('data');

    expect(array_column($listed, 'id'))->toBe([$workspace->id]);

    // Its gear opens the organization's own settings, on the app host.
    $this->get('https://cboxid.com'.str_replace('{id}', $workspace->id, $organization['settingsUrl']))
        ->assertRedirect('/orgs/'.orgSlug($workspace->id).'/settings');
});

it('sends the environment console\'s account links to the app host\'s own account pages', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = aWorkspace();

    serveOnTestHost($environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    actAsEnvironmentAdmin($subjectId, $environment->id);

    $account = $this->get(route('environment.home'))->assertOk()->inertiaProps('shell.account');

    // The console is on the app host now, in the session that holds the account, so its
    // account links are that host's own pages — and they open.
    expect($account['profileUrl'])->toBe('/settings')
        ->and($account['switchAccountUrl'])->toBe('/settings/accounts');

    $this->get('https://cboxid.com'.$account['profileUrl'])->assertOk();
});

/**
 * MOST ORGANIZATIONS ONLY RUN THEIR OWN TEAM. Landing them on a list of identity
 * platforms they never asked for read as "create a project" being the point of Cbox ID;
 * the home leads with their people and offers to build sign-in for a product, and the list
 * joins the rail once there is something in it.
 */
it('opens an organization with no identity platform on its home, and adds the list once it creates one', function (): void {
    ['subjectId' => $subjectId, 'project' => $project, 'environment' => $environment] = aWorkspace();
    Environment::query()->whereKey($environment->id)->delete();
    Project::query()->whereKey($project->id)->delete();
    signInAsMember($subjectId);
    confirmStepUp();

    $this->get('https://cboxid.com/dashboard')->assertRedirect(route('orgs.overview'));

    $home = $this->get('https://cboxid.com'.orgPath('/overview'))->assertOk();

    expect($home->inertiaPage()['component'])->toBe('console/orgs/home')
        ->and($home->inertiaProps('people'))->toBe(1)
        ->and($home->inertiaProps('platformsHref'))->toBeNull()
        ->and($home->inertiaProps('createPlatformHref'))->toBe(route('orgs.projects.create'))
        ->and(railOf('https://cboxid.com'.orgPath('/overview'))['identity-platform'])
        ->toContain('orgs.overview')->not->toContain('orgs.projects');

    // Created with its production environment, so it is usable when its page opens.
    $this->post('https://cboxid.com'.orgPath('/projects'), ['name' => 'Ledger'])->assertSessionHasNoErrors();
    $created = Project::query()->where('name', 'Ledger')->sole();
    nextRequest();

    expect(Environment::query()->where('project_id', $created->id)->pluck('type')->map(fn ($type) => $type->value)->all())->toBe(['production'])
        ->and(railOf('https://cboxid.com'.orgPath('/overview'))['identity-platform'])->toContain('orgs.projects');
});

it('creates an identity platform only on a plan it offered, and sends a paid one to its review', function (): void {
    ['subjectId' => $subjectId] = aWorkspace();
    signInAsMember($subjectId);
    confirmStepUp();

    app()->instance(PlanOffers::class, new class implements PlanOffers
    {
        public function forOrganization(string $organizationId): array
        {
            return [
                ['key' => 'id-free', 'name' => 'Free', 'priceMinor' => 0, 'totalMinor' => 0, 'currency' => 'DKK', 'interval' => 'month', 'environments' => 1],
                ['key' => 'id-pro', 'name' => 'Pro', 'priceMinor' => 49900, 'totalMinor' => 62375, 'currency' => 'DKK', 'interval' => 'month', 'environments' => 3],
            ];
        }

        public function reviewUrl(string $projectId, string $plan): ?string
        {
            return '/review/'.$projectId.'/'.$plan;
        }
    });

    $form = $this->get('https://cboxid.com'.orgPath('/projects/new'))->assertOk();
    expect($form->inertiaProps('defaultPlan'))->toBe('id-free');

    // A key the form never offered makes nothing.
    $this->post('https://cboxid.com'.orgPath('/projects'), ['name' => 'Forged', 'plan' => 'id-enterprise'])
        ->assertSessionHasErrors('plan');
    expect(Project::query()->where('name', 'Forged')->exists())->toBeFalse();

    // A paid plan is confirmed on Billing's review, after the platform exists.
    $response = $this->post('https://cboxid.com'.orgPath('/projects'), ['name' => 'Ledger', 'plan' => 'id-pro']);
    $created = Project::query()->where('name', 'Ledger')->sole();
    $response->assertRedirect('/review/'.$created->id.'/id-pro');

    // The free one goes straight to its page.
    $this->post('https://cboxid.com'.orgPath('/projects'), ['name' => 'Notes', 'plan' => 'id-free'])
        ->assertRedirect(route('orgs.projects.show', Project::query()->where('name', 'Notes')->sole()->id));
});

it('says inside an environment console whose users these are, with the way back', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment, 'organization' => $workspace, 'project' => $project] = aWorkspace();

    serveOnTestHost($environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    actAsEnvironmentAdmin($subjectId, $environment->id);

    $frame = $this->get(route('environment.home'))->assertOk()->inertiaProps('shell.environmentFrame');

    expect(array_diff_key($frame, ['backHref' => true]))->toBe([
        'platform' => $project->name,
        'organization' => $workspace->name,
    ])->and($frame['backHref'])->toEndWith('/orgs/'.orgSlug($workspace->id));
});
