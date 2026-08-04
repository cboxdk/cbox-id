<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentSudo;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\EnvironmentAdminHandoff;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** Provision an account (owner + first environment) and return them. */
function envAdminSetup(): array
{
    // Every door in this file is under the `/admin` prefix, which carries `multi.tenant`
    // and 404s on a single-tenant deployment — the console is gated on an account member
    // administering one of their ACCOUNT's environments, and a single-tenant install has
    // one environment which is the platform root and belongs to no account.
    // {@see \App\Http\Middleware\RequireMultiTenant}, tests/Feature/SingleTenantAdminConsoleTest.php.
    multiTenantDeployment();

    platformRootEnvironment();
    $r = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    return [
        'member' => $r->member,
        'account' => $r->account,
        'env' => $r->environment,
        'envId' => $r->environment->id,
        // The env's {slug}.{base_domain} tenant host (with base_domains = ['cboxid.com']).
        'host' => $r->environment->slug.'.cboxid.com',
    ];
}

it('authenticates an account member as admin ONLY on their environment\'s host (anti-bleed)', function (): void {
    ['member' => $member, 'envId' => $envId] = envAdminSetup();

    // The session is the member's ordinary PLATFORM-ROOT SUBJECT session — the
    // credential of record — ANCHORED to exactly one environment. The anchor is what
    // this test is about; who the identity is does not change it.
    actAsEnvironmentAdmin($member, $envId);
    expect(session(EnvironmentAdminAuth::ENV_KEY))->toBe($envId);

    $auth = app(EnvironmentAdminAuth::class);

    // On the anchored environment's host → authenticated.
    app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));
    expect($auth->subjectId())->toBe($member->refresh()->subject_id);
    expect($auth->current()?->id)->toBe($member->id);

    // On a DIFFERENT environment's host → nothing, even though the session cookie is
    // the same. Bound env ≠ host env ⇒ no bleed.
    app(EnvironmentContext::class)->set(GenericEnvironment::of('some_other_env'));
    expect($auth->current())->toBeNull();
});

it('refuses a member with no access to the environment', function (): void {
    ['account' => $account, 'envId' => $envId] = envAdminSetup();

    // A viewer scoped to NO environments.
    $members = app(AccountMembers::class);
    $stranger = $members->invite($account->id, 'stranger@acme.example', AccountRole::Viewer);
    $members->activate($stranger->id, 'a-strong-unbreached-passphrase');
    $members->setEnvironmentAccess($stranger->id, all: false, environmentIds: []);

    actAsEnvironmentAdmin($stranger, $envId);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));

    expect(app(EnvironmentAdminAuth::class)->current())->toBeNull();
});

it('redeems a signed handoff into an env-admin session', function (): void {
    ['member' => $member, 'env' => $env, 'envId' => $envId] = envAdminSetup();
    // Reach the environment the way its admin does: on its OWN host. An unmapped host
    // resolves to the platform ROOT, which is where the account plane lives.
    serveOnTestHost($env);

    // The handoff carries the SUBJECT; the account membership behind it is re-resolved
    // on redemption rather than carried in the token.
    $subjectId = $member->refresh()->subject_id;
    $token = app(EnvironmentAdminHandoff::class)->mint($subjectId, $envId);

    $this->get("/admin/handoff?token={$token}")->assertRedirect(route('environment.home'));

    expect(app(EnvironmentAdminAuth::class)->subjectId())->toBe($subjectId)
        ->and(session(EnvironmentAdminAuth::ENV_KEY))->toBe($envId);
});

it('renders the env-admin console (overview, organizations, users) for an admin session', function (): void {
    ['member' => $member, 'env' => $env, 'envId' => $envId] = envAdminSetup();
    serveOnTestHost($env);
    actAsEnvironmentAdmin($member, $envId);

    // The token vault is the one capability here behind a step-up, so this sweep takes it
    // once rather than dropping the two pages that would otherwise 302. Confirming it up
    // front keeps the sweep about RENDERING; the gate itself is asserted on its own in
    // EnvironmentAdminCrudTest, where a page that stopped redirecting would be caught.
    app(EnvironmentSudo::class)->confirm();

    foreach ([
        '/admin' => 'Overview',
        '/admin/organizations' => 'Organizations',
        '/admin/organizations/new' => 'New organization',
        '/admin/users' => 'Users',
        '/admin/users/new' => 'New user',
        // "Apps &amp; API keys" once the two consoles merged onto one component with one
        // title; matched on the half that carries no escapable character.
        '/admin/applications' => 'API keys',
        '/admin/single-sign-on' => 'Single sign-on',
        '/admin/single-sign-on/new' => 'connection',
        '/admin/login-methods' => 'Login methods',
        '/admin/login-methods/new' => 'method',
        '/admin/directories' => 'Sync users in',
        '/admin/directories/new' => 'directory',
        // "Sync users out", the name its page, its help topic and the organization
        // plane's registry have always used; only the environment rail said "Outbound
        // sync", one line under the "Sync users in" it is the pair of.
        '/admin/outbound-sync' => 'Sync users out',
        '/admin/outbound-sync/new' => 'connection',
        '/admin/roles' => 'Roles',
        '/admin/roles/new' => 'role',
        '/admin/access-reviews/new' => 'review',
        '/admin/conflict-rules/new' => 'New role conflict',
        '/admin/applications/new' => 'application',
        '/admin/webhooks/new' => 'webhook',
        '/admin/event-hooks/new' => 'hook',
        '/admin/stored-tokens/new' => 'token',
        '/admin/log-streaming/new' => 'stream',
        '/admin/access-reviews' => 'Access reviews',
        '/admin/conflict-rules' => 'Role conflicts',
        '/admin/webhooks' => 'Webhooks',
        '/admin/event-hooks' => 'Inline hooks',
        '/admin/stored-tokens' => 'Token vault',
        // Likewise: the page is headed "Activity log" on both planes.
        '/admin/audit' => 'Activity log',
        '/admin/log-streaming' => 'Log streaming',
        '/admin/analytics' => 'Analytics',
        '/admin/approvals' => 'Agent approvals',
        '/admin/settings' => 'Integration',
    ] as $path => $needle) {
        $this->get($path)->assertOk()->assertSee($needle);
    }
});

it('refuses a handoff minted for a different environment than the host', function (): void {
    ['member' => $member, 'env' => $env] = envAdminSetup();
    serveOnTestHost($env);

    // Token says env X, but this host resolves env `$envId` → refused.
    $token = app(EnvironmentAdminHandoff::class)->mint((string) $member->refresh()->subject_id, 'a_different_env');

    $this->get("/admin/handoff?token={$token}")->assertRedirect(route('admin.login'));
    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
});

it('bounces an unauthenticated tenant admin to the ROOT open-environment handoff (multi-tenant pull flow)', function (): void {
    ['envId' => $envId, 'host' => $host] = envAdminSetup();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    // No credential form on the tenant host — the admin is sent to the root's
    // "open environment" door, which authenticates once and hands off back here.
    $this->get("https://{$host}/admin/organizations")
        ->assertRedirect('https://cboxid.com/workspace/open/'.$envId);
});

it('sends /admin/login to the root rather than serving a door of its own', function (): void {
    ['envId' => $envId, 'host' => $host] = envAdminSetup();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    // There is no credential form behind this path any more — account credentials are
    // never entered on a tenant-controlled host, and the form that used to be here was
    // reachable by no correctly-configured deployment. The name survives because every
    // "send them to the door" redirect in the console uses it (the handoff's refusals,
    // logout, the sudo screen), and it now sits BEHIND `env.admin`: the gate IS the door,
    // so the bounce below comes from the middleware rather than from a mount() that had
    // to remember to duplicate it.
    $this->get("https://{$host}/admin/login")
        ->assertRedirect('https://cboxid.com/workspace/open/'.$envId);
});

/**
 * The one state in which the bounce above has nowhere to go.
 *
 * Multi-tenancy claimed and no account host named anywhere is
 * {@see PlaneResolver::misconfigured()} — the first-run screen refuses to
 * install into it and `cbox-id:doctor` reports it, so the only way to arrive here is
 * configuration changed after a good install. This used to answer with a local credential
 * form: a second account-credential store, on a tenant-controlled host, offered because
 * the deployment was broken. A configuration error must not look like a working product
 * to everyone except the person whose password it takes.
 */
it('refuses the admin console outright when the deployment names no account host', function (): void {
    ['env' => $env] = envAdminSetup();
    serveOnTestHost($env);

    config()->set('cbox-id.tenancy.account_host', null);
    config()->set('cbox-id.environments.base_domains', []);
    config()->set('cbox-id.environments.platform_root_hosts', []);

    expect(app(PlaneResolver::class)->misconfigured())->toBeTrue();

    // 503, not 404: the console is not absent, the deployment is unfinished, and an
    // operator reading a log needs to be able to tell those apart.
    $this->get('/admin/organizations')->assertStatus(503);
    $this->get('/admin/login')->assertStatus(503);
})->group('security');

it('has no admin door at all on a single-host deployment', function (): void {
    // This asserted the OPPOSITE until the console became multi-tenant-only: that a
    // self-hosted single-host install served the LOCAL "sign in as admin" form, on the
    // reasoning that the account console and this admin console share an origin there so
    // the form was safe. The form was safe and it was also useless — the console is gated
    // on an account member administering one of their ACCOUNT's environments, and the lone
    // environment on a single-host install is the platform root, which belongs to no
    // account. It took correct credentials and refused them, blaming the credentials.
    //
    // Asserted here rather than only in SingleTenantAdminConsoleTest because this one
    // starts from a real account, a real environment and a host that resolves to it: the
    // 404 is the deployment shape, not an empty database.
    ['env' => $env] = envAdminSetup();
    serveOnTestHost($env);

    config()->set('cbox-id.tenancy.multi_tenant', false);
    config()->set('cbox-id.tenancy.account_host', null);
    config()->set('cbox-id.environments.base_domains', []);

    $this->get('/admin/organizations')->assertNotFound();
    $this->get('/admin/login')->assertNotFound();
})->group('security');

/*
|--------------------------------------------------------------------------
| Privilege boundary — "accessible" is not "administrable"
|--------------------------------------------------------------------------
| A viewer/billing account member defaults to all_environments=true on invite,
| so they CAN reach every environment. Administering an environment's control
| plane is an owner/admin/developer capability (AccountRole::canManageEnvironments).
| The env-admin session chokepoint and the handoff-mint door must both refuse the
| scoped roles even though the environment is reachable.
*/

it('refuses a reachable-but-unprivileged member at the env-admin session chokepoint', function (): void {
    ['account' => $account, 'envId' => $envId] = envAdminSetup();
    $members = app(AccountMembers::class);

    foreach ([AccountRole::Viewer, AccountRole::Billing] as $role) {
        $m = $members->invite($account->id, $role->value.'-choke@acme.example', $role);
        $members->activate($m->id, 'a-strong-unbreached-passphrase');

        // Precondition: the default invite grants access to the environment.
        expect($members->accessibleEnvironmentIds($members->find($m->id)))->toContain($envId);

        actAsEnvironmentAdmin($m, $envId);
        app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));

        // Reachable, yet the admin session must not resolve — no control-plane power.
        expect(app(EnvironmentAdminAuth::class)->current())->toBeNull();
    }
});

it('admits owner, admin, and developer to the env-admin session', function (): void {
    ['account' => $account, 'member' => $owner, 'envId' => $envId] = envAdminSetup();
    $members = app(AccountMembers::class);

    $admit = ['owner' => $owner];
    foreach ([AccountRole::Admin, AccountRole::Developer] as $role) {
        $m = $members->invite($account->id, $role->value.'-ok@acme.example', $role);
        $members->activate($m->id, 'a-strong-unbreached-passphrase');
        $admit[$role->value] = $m;
    }

    foreach ($admit as $member) {
        actAsEnvironmentAdmin($member, $envId);
        app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));
        expect(app(EnvironmentAdminAuth::class)->current()?->id)->toBe($member->id);
    }
});

it('refuses to mint a handoff for a reachable-but-unprivileged member (fail before the credential exists)', function (): void {
    ['account' => $account, 'envId' => $envId] = envAdminSetup();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $members = app(AccountMembers::class);

    $viewer = $members->invite($account->id, 'viewer-mint@acme.example', AccountRole::Viewer);
    $members->activate($viewer->id, 'a-strong-unbreached-passphrase');

    // Viewer reaches the env but is refused the mint — 403, no handoff token issued.
    signInAsMember($viewer);
    $this->get(route('workspace.environment.open', $envId))
        ->assertForbidden();

    // A developer is bounced to the environment host to redeem — a redirect, not a 403.
    $dev = $members->invite($account->id, 'dev-mint@acme.example', AccountRole::Developer);
    $members->activate($dev->id, 'a-strong-unbreached-passphrase');
    signInAsMember($dev);
    $this->get(route('workspace.environment.open', $envId))
        ->assertRedirect();
});

/**
 * The anchor, isolated from the access check that was standing in front of it.
 *
 * The original anti-bleed test used an environment the member had no access to, so
 * DELETING the anchor comparison left it green: the per-request access check refused
 * the session anyway. Two guards, one test, and only one of them was actually being
 * exercised.
 *
 * This is the case where they disagree — an owner with `all_environments`, so they may
 * administer BOTH, holding a session anchored to the first while standing on the second's
 * host. Access says yes; the anchor says no, and the anchor is right. An admin session is
 * minted by a one-time, audited handoff for ONE environment, and the session cookie is
 * shared across `*.cboxid.com` — without the anchor, redeeming a handoff for staging
 * would silently open production too.
 */
it('refuses a session anchored to one environment on another the same admin may also administer', function (): void {
    ['member' => $member, 'account' => $account, 'envId' => $envId] = envAdminSetup();

    $second = app(AccountProvisioner::class)->addEnvironment(
        $account->projects()->firstOrFail(),
        'Staging',
    );

    // Access is NOT what refuses here — state it, or this is the old test again.
    expect(in_array($second->id, app(AccountMembers::class)->accessibleEnvironmentIds($member), true))
        ->toBeTrue('fixture: the admin must be entitled to BOTH, or the anchor is not what holds');

    actAsEnvironmentAdmin($member, $envId);

    $auth = app(EnvironmentAdminAuth::class);

    app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));
    expect($auth->current()?->id)->toBe($member->id);

    app(EnvironmentContext::class)->set(GenericEnvironment::of($second->id));
    expect($auth->current())
        ->toBeNull('a session anchored to one environment administered another on the same cookie');
})->group('security');
