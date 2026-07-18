<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
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
    $r = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    return ['member' => $r->member, 'account' => $r->account, 'envId' => $r->environment->id];
}

it('authenticates an account member as admin ONLY on their environment\'s host (anti-bleed)', function (): void {
    ['member' => $member, 'envId' => $envId] = envAdminSetup();

    session()->put(EnvironmentAdminAuth::SESSION_KEY, $member->id);
    session()->put(EnvironmentAdminAuth::ENV_KEY, $envId);
    $auth = app(EnvironmentAdminAuth::class);

    // On the bound environment's host → authenticated.
    app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));
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

    session()->put(EnvironmentAdminAuth::SESSION_KEY, $stranger->id);
    session()->put(EnvironmentAdminAuth::ENV_KEY, $envId);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));

    expect(app(EnvironmentAdminAuth::class)->current())->toBeNull();
});

it('redeems a signed handoff into an env-admin session', function (): void {
    ['member' => $member, 'envId' => $envId] = envAdminSetup();
    // Make the HTTP request resolve to this environment (SetEnvironment reads this).
    config(['cbox-id.environments.default' => $envId]);

    $token = app(EnvironmentAdminHandoff::class)->mint($member->id, $envId);

    $this->get("/admin/handoff?token={$token}")->assertRedirect(route('environment.home'));

    expect(session(EnvironmentAdminAuth::SESSION_KEY))->toBe($member->id)
        ->and(session(EnvironmentAdminAuth::ENV_KEY))->toBe($envId);
});

it('renders the env-admin console (overview, organizations, users) for an admin session', function (): void {
    ['member' => $member, 'envId' => $envId] = envAdminSetup();
    config(['cbox-id.environments.default' => $envId]);
    session()->put(EnvironmentAdminAuth::SESSION_KEY, $member->id);
    session()->put(EnvironmentAdminAuth::ENV_KEY, $envId);

    foreach ([
        '/admin' => 'Overview',
        '/admin/organizations' => 'Organizations',
        '/admin/organizations/new' => 'New organization',
        '/admin/users' => 'Users',
        '/admin/users/new' => 'New user',
        '/admin/applications' => 'Applications',
        '/admin/single-sign-on' => 'Single sign-on',
        '/admin/single-sign-on/new' => 'connection',
        '/admin/login-methods' => 'Login methods',
        '/admin/login-methods/new' => 'method',
        '/admin/directories' => 'Directories',
        '/admin/directories/new' => 'directory',
        '/admin/outbound-sync' => 'Outbound sync',
        '/admin/outbound-sync/new' => 'connection',
        '/admin/roles' => 'Roles',
        '/admin/roles/new' => 'role',
        '/admin/access-reviews/new' => 'review',
        '/admin/conflict-rules/new' => 'rule',
        '/admin/applications/new' => 'application',
        '/admin/webhooks/new' => 'webhook',
        '/admin/event-hooks/new' => 'hook',
        '/admin/stored-tokens/new' => 'token',
        '/admin/log-streaming/new' => 'stream',
        '/admin/access-reviews' => 'Access reviews',
        '/admin/conflict-rules' => 'Conflict rules',
        '/admin/webhooks' => 'Webhooks',
        '/admin/event-hooks' => 'Event hooks',
        '/admin/stored-tokens' => 'Stored tokens',
        '/admin/audit' => 'Audit log',
        '/admin/log-streaming' => 'Log streaming',
        '/admin/analytics' => 'Analytics',
        '/admin/approvals' => 'Agent approvals',
        '/admin/settings' => 'Integration',
    ] as $path => $needle) {
        $this->get($path)->assertOk()->assertSee($needle);
    }
});

it('refuses a handoff minted for a different environment than the host', function (): void {
    ['member' => $member, 'envId' => $envId] = envAdminSetup();
    config(['cbox-id.environments.default' => $envId]);

    // Token says env X, but this host resolves env `$envId` → refused.
    $token = app(EnvironmentAdminHandoff::class)->mint($member->id, 'a_different_env');

    $this->get("/admin/handoff?token={$token}")->assertRedirect(route('admin.login'));
    expect(session(EnvironmentAdminAuth::SESSION_KEY))->toBeNull();
});
