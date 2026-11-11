<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/*
|--------------------------------------------------------------------------
| The environment console on the APP HOST — the new boundary (plan step 3, risk R1).
|--------------------------------------------------------------------------
|
| The console used to be `/admin` on each environment's own host, behind a session of its
| own redeemed from a signed handoff. It is `/orgs/{org}/projects/{project}/environments/
| {environment}/…` on the app host now, administered from the person's ordinary app-host
| session, and `ResolveEnvironmentFromPath` is the only door: it resolves the environment
| from the path, authorizes the person for it on every request and makes it the request's
| environment before route model binding runs. These tests hold that door shut where it
| must be shut.
*/

/** Provision an account (owner + first environment) and return them. */
function envAdminSetup(string $email = 'owner@acme.example', string $name = 'Acme'): array
{
    multiTenantDeployment();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    platformRootEnvironment();
    $r = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: $name,
        ownerEmail: $email,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    return [
        'member' => $r->membership, 'subjectId' => $r->owner->id,
        'organization' => $r->organization,
        'project' => $r->project,
        'env' => $r->environment,
        'envId' => $r->environment->id,
    ];
}

/** A second environment in the same project. */
function secondEnvironment(Project $project, string $slug = 'acme-sandbox'): Environment
{
    return app(PlatformRoot::class)->run(fn (): Environment => Environment::query()->create([
        'name' => 'Sandbox',
        'slug' => $slug,
        'type' => EnvironmentType::Sandbox,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'project_id' => $project->id,
        'settings' => [],
    ]));
}

it('names each environment by organization, project and environment on the app host', function (): void {
    ['organization' => $organization, 'project' => $project, 'env' => $env] = envAdminSetup();

    expect(app(EnvironmentConsolePath::class)->for($env->id))
        ->toBe('orgs/'.$organization->slug.'/projects/'.$project->slug.'/environments/'.$env->slug)
        ->and(app(EnvironmentConsolePath::class)->for(platformRootEnvironment()->id))->toBe('admin/environment');
});

it('renders the env-admin console (overview, organizations, users) for an admin session', function (): void {
    ['member' => $member, 'envId' => $envId] = envAdminSetup();
    actAsEnvironmentAdmin($member->user_id, $envId);

    // The token vault is the one capability here behind a step-up, so this sweep takes it
    // once rather than dropping the two pages that would otherwise 302. Confirming it up
    // front keeps the sweep about RENDERING; the gate itself is asserted on its own in
    // EnvironmentAdminCrudTest, where a page that stopped redirecting would be caught.
    confirmEnvironmentStepUp();

    foreach ([
        envConsole() => 'Overview',
        envConsole('/organizations') => 'Organizations',
        envConsole('/organizations/new') => 'New organization',
        envConsole('/users') => 'Users',
        envConsole('/users/new') => 'New user',
        // "Applications" on both consoles — one component, one title, one slug.
        envConsole('/apps') => 'Applications',
        envConsole('/single-sign-on') => 'Enterprise SSO',
        envConsole('/single-sign-on/new') => 'connection',
        // Renamed: it registers SAML service providers that trust us, which is the
        // opposite direction from Enterprise SSO, and "Login methods" named that one.
        envConsole('/saml-apps') => 'SAML apps',
        envConsole('/saml-apps/new') => 'SAML app',
        envConsole('/sync-in') => 'Directory Sync',
        envConsole('/sync-in/new') => 'directory',
        // One name on both rails and on the page: the rail and the heading drifted apart
        // here once ("Outbound sync" over a page headed otherwise), which is what this
        // sweep exists to notice.
        envConsole('/sync-out') => 'Outbound provisioning',
        envConsole('/sync-out/new') => 'connection',
        envConsole('/roles') => 'Roles',
        envConsole('/roles/new') => 'role',
        envConsole('/access-reviews/new') => 'review',
        envConsole('/role-conflicts/new') => 'New role conflict',
        envConsole('/apps/new') => 'application',
        envConsole('/inline-hooks/new') => 'hook',
        envConsole('/token-vault/new') => 'token',
        envConsole('/log-streaming/new') => 'stream',
        envConsole('/access-reviews') => 'Access reviews',
        envConsole('/role-conflicts') => 'Role conflicts',

        envConsole('/inline-hooks') => 'Hooks',
        envConsole('/token-vault') => 'Token vault',
        envConsole('/log-streaming') => 'Log streams',
        // "Usage" on BOTH planes now. It was "Analytics" here and "Usage" on the
        // organization plane, over the same `auth.*` counters — and this plane's version
        // was the primitive one: raw metric keys, no labels, no time window.
        envConsole('/usage') => 'Usage',
        envConsole('/approvals') => 'Approvals',
    ] as $path => $needle) {
        $this->get($path)->assertOk()->assertSee($needle);
    }

    /*
     * AND THE PORTED ONES, asked the only way they can be asked.
     *
     * These pages render in the browser, so there is no heading in the response to match
     * — the word the sweep above looks for is drawn by React from a prop. What the
     * response carries instead is the page object, and the two things worth holding are
     * in it: WHICH component the server chose, and what it says the page is called. That
     * is strictly more than "the word appears somewhere in the document", which would
     * also pass for a page that mentioned it in a link to somewhere else.
     */
    foreach ([
        envConsole('/webhooks') => ['console/webhooks/index', 'Webhooks'],
        envConsole('/webhooks/new') => ['console/webhooks/create', 'New webhook'],
        envConsole('/audit') => ['console/audit', 'Audit log'],
        envConsole('/settings') => ['console/settings', 'Settings'],
        envConsole('/branding') => ['console/branding', 'Branding'],
        envConsole('/sign-in-rules') => ['console/auth-policy', 'Authentication policy'],
    ] as $path => [$component, $title]) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component($component)
                ->where('title', $title));
    }
});

it('admits a member only to the environments their access reaches: 404 on the others', function (): void {
    ['organization' => $organization, 'project' => $project, 'envId' => $envId] = envAdminSetup();
    $sandbox = secondEnvironment($project);

    [$limited] = addMember($organization->id, MembershipRole::Developer, 'limited@acme.example');
    grantNativeProductRoles($limited, ['developer']);
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->setEnvironmentAccess($limited->organization_id, $limited->user_id, all: false, environmentIds: [$envId]));

    actAsEnvironmentAdmin($limited->user_id, $envId);
    $this->get(envConsole('/users'))->assertOk();

    useEnvironmentConsole($sandbox->id);
    $this->get(envConsole('/users'))->assertNotFound();
    $this->get(envConsole())->assertNotFound();
})->group('security');

it('answers 404 to a person who administers another organization entirely', function (): void {
    ['envId' => $acme] = envAdminSetup();
    ['subjectId' => $globexOwner] = envAdminSetup('owner@globex.example', 'Globex');

    actAsEnvironmentAdmin($globexOwner, app(Environment::class)->newQuery()->where('slug', 'globex')->value('id') ?? '');
    useEnvironmentConsole($acme);

    $this->get(envConsole())->assertNotFound();
    $this->get(envConsole('/users'))->assertNotFound();
    $this->post(envConsole('/organizations'), ['name' => 'Nope'])->assertNotFound();
})->group('security');

it('answers 404 when the path\'s organization, project and environment do not belong together', function (): void {
    ['organization' => $organization, 'project' => $project, 'env' => $env, 'subjectId' => $owner] = envAdminSetup();
    actAsEnvironmentAdmin($owner, $env->id);

    $host = 'http://'.config('cbox-id.tenancy.account_host');

    $this->get($host.'/orgs/'.$organization->slug.'/projects/'.$project->slug.'/environments/'.$env->slug)->assertOk();
    $this->get($host.'/orgs/'.$organization->slug.'/projects/not-this-project/environments/'.$env->slug)->assertNotFound();
    $this->get($host.'/orgs/not-this-org/projects/'.$project->slug.'/environments/'.$env->slug)->assertNotFound();
    $this->get($host.'/orgs/'.$organization->slug.'/projects/'.$project->slug.'/environments/'.platformRootEnvironment()->slug)->assertNotFound();
})->group('security');

it('gives a Reader the console to read and nothing to change', function (): void {
    ['organization' => $organization, 'envId' => $envId] = envAdminSetup();

    [$reader] = addMember($organization->id, MembershipRole::Viewer, 'reader@acme.example');
    grantNativeProductRoles($reader, ['viewer']);

    actAsEnvironmentAdmin($reader->user_id, $envId);

    $this->get(envConsole('/users'))->assertOk();
    $this->post(envConsole('/organizations'), ['name' => 'Nope'])->assertForbidden();
})->group('security');

it('binds a record id from another environment to nothing: 404, never the other environment\'s record', function (): void {
    ['project' => $project, 'envId' => $envId, 'subjectId' => $owner] = envAdminSetup();
    $sandbox = secondEnvironment($project);

    // A user who lives in the SANDBOX, asked for under PRODUCTION's path.
    $stranger = app(EnvironmentContext::class)->runAs($sandbox, fn () => app(Subjects::class)->create('sandbox-user@acme.example', 'Sandbox User', 'a-strong-unbreached-passphrase'));
    $local = app(EnvironmentContext::class)->runAs(Environment::query()->findOrFail($envId), fn () => app(Subjects::class)->create('prod-user@acme.example', 'Prod User', 'a-strong-unbreached-passphrase'));

    actAsEnvironmentAdmin($owner, $envId);

    $this->get(envConsole('/users/'.$local->id))->assertOk();
    $this->get(envConsole('/users/'.$stranger->id))->assertNotFound();

    // …and the same id under the sandbox's own path is that user.
    useEnvironmentConsole($sandbox->id);
    $this->get(envConsole('/users/'.$stranger->id))->assertOk();
})->group('security');

it('sends a signed-out visitor to the app host\'s sign-in, and back afterwards', function (): void {
    ['envId' => $envId] = envAdminSetup();
    useEnvironmentConsole($envId);
    serveConsoleOn();

    // The app host's own sign-in, in the session the console is served in.
    $this->get(envConsole('/users'))->assertRedirect('http://'.parse_url(envConsole(), PHP_URL_HOST).'/login');
    expect(session('url.intended'))->toBe(envConsole('/users'));
});

it('redirects a tenant host\'s old /admin addresses to the same page on the app host', function (): void {
    ['env' => $env, 'subjectId' => $owner] = envAdminSetup();
    actAsEnvironmentAdmin($owner, $env->id);
    $host = $env->slug.'.cboxid.com';

    $this->get('https://'.$host.'/admin')
        ->assertStatus(302)
        ->assertHeader('Deprecation', 'true')
        ->assertRedirect('https://'.config('cbox-id.tenancy.account_host').'/'.app(EnvironmentConsolePath::class)->for($env->id));

    $this->get('https://'.$host.'/admin/users?page=2')
        ->assertStatus(302)
        ->assertRedirect('https://'.config('cbox-id.tenancy.account_host').'/'.app(EnvironmentConsolePath::class)->for($env->id).'/users?page=2');

    // Only a page is redirected. Nothing under `/admin` on a tenant's host acts any more.
    $this->post('https://'.$host.'/admin/organizations', ['name' => 'Nope'])->assertStatus(405);
    $this->post('https://'.$host.'/admin/handoff', ['token' => 'x'])->assertStatus(405);

    // …and the console itself does not answer on a tenant's host.
    $this->get('https://'.$host.'/'.app(EnvironmentConsolePath::class)->for($env->id).'/users')->assertNotFound();
})->group('security');

it('redirects the root host\'s old /admin addresses to /admin/environment', function (): void {
    envAdminSetup();

    $this->get('https://cboxid.com/admin/social-sign-in')
        ->assertStatus(302)
        ->assertRedirect('https://cboxid.com/admin/environment/social-sign-in');
});

it('has no console for a suspended environment, not even for its owner', function (): void {
    ['env' => $env, 'subjectId' => $owner] = envAdminSetup();
    actAsEnvironmentAdmin($owner, $env->id);

    $this->get(envConsole('/users'))->assertOk();

    // An operator's suspension is a kill switch for the console too, as it was on the
    // environment's own host: no keys, vault or sign-in to change behind it.
    app(PlatformRoot::class)->run(fn () => $env->forceFill(['status' => EnvironmentStatus::Suspended])->save());

    $this->get(envConsole('/users'))->assertNotFound();
    $this->get(envConsole())->assertNotFound();
})->group('security');

it('counts every use of an old console address', function (): void {
    ['env' => $env, 'subjectId' => $owner] = envAdminSetup();
    actAsEnvironmentAdmin($owner, $env->id);

    Log::spy();

    $this->get('https://'.$env->slug.'.cboxid.com/admin/users')->assertStatus(302);

    Log::shouldHaveReceived('notice')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, '/admin/users') && ($context['surface'] ?? null) === 'console_url',
    )->once();
});

it('has no environment console on a single-tenant deployment', function (): void {
    ['envId' => $envId, 'subjectId' => $owner] = envAdminSetup();
    actAsEnvironmentAdmin($owner, $envId);

    singleTenantDeployment();

    $this->get(envConsole('/users'))->assertNotFound();
    $this->get('/admin/users')->assertNotFound();
});

it('refuses a reachable-but-unprivileged member', function (): void {
    ['organization' => $account, 'envId' => $envId] = envAdminSetup();
    $members = app(Memberships::class);

    foreach ([MembershipRole::Viewer, MembershipRole::Member] as $role) {
        [$m] = addMember($account->id, $role, $role->value.'-choke@acme.example');

        // Precondition: the default invite grants access to the environment.
        expect(app(PlatformRoot::class)->run(fn (): array => $members->accessibleEnvironmentIds($m->organization_id, $m->user_id)))->toContain($envId);

        actAsEnvironmentAdmin($m->user_id, $envId);
        app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));

        // Reachable, yet no administrator resolves — no control-plane power.
        expect(app(EnvironmentAdminAuth::class)->membership())->toBeNull();
        $this->get(envConsole('/users'))->assertNotFound();
    }
})->group('security');

it('admits owner, admin, and developer', function (): void {
    ['organization' => $account, 'member' => $owner, 'envId' => $envId] = envAdminSetup();

    $admit = ['owner' => $owner];
    foreach ([MembershipRole::Admin, MembershipRole::Developer] as $role) {
        [$m] = addMember($account->id, $role, $role->value.'-ok@acme.example');
        grantNativeProductRoles($m, [$role->value]);
        $admit[$role->value] = $m;
    }

    foreach ($admit as $member) {
        actAsEnvironmentAdmin($member->user_id, $envId);
        app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));
        expect(app(EnvironmentAdminAuth::class)->membership()?->id)->toBe($member->id);
        $this->get(envConsole('/users'))->assertOk();
    }
});

it('answers for nobody outside an environment console request', function (): void {
    ['envId' => $envId, 'subjectId' => $owner] = envAdminSetup();
    actAsEnvironmentAdmin($owner, $envId);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));
    expect(app(EnvironmentAdminAuth::class)->subjectId())->toBe($owner);

    // A request that is not a console request: the same person, the same environment
    // context — and no administrator, because only the path middleware says "console".
    request()->attributes->remove(EnvironmentAdminAuth::ENVIRONMENT_CONSOLE);
    app()->forgetScopedInstances();
    app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));

    expect(app(EnvironmentAdminAuth::class)->subjectId())->toBeNull();
})->group('security');

/*
|--------------------------------------------------------------------------
| Step-up: three windows that never satisfy one another.
|--------------------------------------------------------------------------
*/

it('keeps the environment step-up apart from the organization step-up, both ways', function (): void {
    ['envId' => $envId, 'subjectId' => $owner] = envAdminSetup();
    actAsEnvironmentAdmin($owner, $envId);

    // The organization's `/sudo` confirmed: an environment action still asks.
    app(Sudo::class)->confirm();
    $this->get(envConsole('/token-vault'))->assertRedirect();

    // The environment's confirmed: the organization's window is still shut.
    confirmEnvironmentStepUp();
    $this->get(envConsole('/token-vault'))->assertOk();
    session()->forget(Sudo::SESSION_KEY);
    expect(app(Sudo::class)->confirmed())->toBeFalse();
})->group('security');

it('binds an environment step-up to the environment it was confirmed in', function (): void {
    ['project' => $project, 'envId' => $envId, 'subjectId' => $owner] = envAdminSetup();
    $sandbox = secondEnvironment($project);

    actAsEnvironmentAdmin($owner, $sandbox->id);
    confirmEnvironmentStepUp();
    $this->get(envConsole('/token-vault'))->assertOk();

    useEnvironmentConsole($envId);
    $this->get(envConsole('/token-vault'))->assertRedirect();
})->group('security');

it('serves the environment step-up under the environment\'s own path, with its mobile door', function (): void {
    ['envId' => $envId, 'subjectId' => $owner] = envAdminSetup();
    actAsEnvironmentAdmin($owner, $envId);

    expect(route('environment.sudo'))->toBe(envConsole('/sudo'))
        ->and(route('environment.sudo.mobile'))->toBe(envConsole('/sudo/mobile'));

    $this->get(envConsole('/sudo'))->assertOk();
    $this->getJson(envConsole('/sudo/mobile'))->assertStatus(200);
})->group('security');
