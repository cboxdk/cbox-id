<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\WorkspaceSudo;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountApiKeys;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\Accounts;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Livewire\Volt\Volt;

/** Invite + activate a member with a role, returning them signed-in-ready. */
if (! function_exists('memberWithRole')) {
    function memberWithRole(string $accountId, AccountRole $role, string $email): AccountMember
    {
        $members = app(AccountMembers::class);
        $m = $members->invite($accountId, $email, $role);
        $members->activate($m->id, 'a-strong-unbreached-passphrase');

        return $members->find($m->id);
    }
}

if (! function_exists('provisionAccount')) {
    /**
     * Provision an account and return its member/account/project/environment.
     *
     * @return array{member: AccountMember, account: Account, project: Project, environment: Environment}
     */
    function provisionAccount(string $email = 'owner@acme.example'): array
    {
        $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
            accountName: 'Acme',
            ownerEmail: $email,
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        return ['member' => $result->member, 'account' => $result->account, 'project' => $result->project, 'environment' => $result->environment];
    }
}

it('renders the workspace sign-in for guests', function (): void {
    $this->get(route('workspace.login'))
        ->assertOk()
        ->assertSee('Sign in to your workspace');
});

it('redirects guests away from the workspace home', function (): void {
    $this->get(route('workspace.home'))->assertRedirect(route('workspace.login'));
});

it('remembers the intended destination when a guest hits open-environment (handoff round-trip)', function (): void {
    ['environment' => $environment] = provisionAccount();

    // A guest bounced here from a tenant admin console must, after signing in, land
    // back on the mint step — so the intended URL is stashed for redirect()->intended().
    $this->get(route('workspace.environment.open', $environment->id))
        ->assertRedirect(route('workspace.login'))
        ->assertSessionHas('url.intended', route('workspace.environment.open', $environment->id));
});

it('renders the workspace home with the account\'s projects', function (): void {
    ['member' => $member] = provisionAccount();

    // Home is the Projects launchpad — the account's default project card, with its
    // environment count.
    $this->withSession([AccountAuth::SESSION_KEY => $member->id])
        ->get(route('workspace.home'))
        ->assertOk()
        ->assertSee('Projects')
        ->assertSee('Acme')          // the default project is named after the account
        ->assertSee('1 of 2');       // 1 of 2 environments
});

it('links each environment out to its own host-resolved URL on the project detail', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $staging = app(AccountProvisioner::class)->addEnvironment($project, 'Staging');

    // The project detail lists each environment as a link to its own
    // {slug}.{base_domain} host — no session "current environment" is pinned.
    $this->withSession([AccountAuth::SESSION_KEY => $member->id])
        ->get(route('workspace.projects.show', $project->id))
        ->assertOk()
        ->assertSee('https://acme.cboxid.com')
        ->assertSee('https://'.$staging->slug.'.cboxid.com');
});

it('renders the members roster with the signed-in member marked', function (): void {
    ['member' => $member] = provisionAccount('dana@acme.example');

    $this->withSession([AccountAuth::SESSION_KEY => $member->id])
        ->get(route('workspace.members'))
        ->assertOk()
        ->assertSee('Members')
        ->assertSee('dana@acme.example')
        ->assertSee('You');
});

it('renders billing with the real environment allowance', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    app(AccountProvisioner::class)->addEnvironment($project, 'Staging');

    $this->withSession([AccountAuth::SESSION_KEY => $member->id])
        ->get(route('workspace.billing'))
        ->assertOk()
        ->assertSee('Billing')
        // 2 of 2 environments used — real figures, no fabricated usage.
        ->assertSee('2 of 2')
        ->assertSee('How pricing works');
});

it('guards the members and billing pages behind the account session', function (): void {
    $this->get(route('workspace.members'))->assertRedirect(route('workspace.login'));
    $this->get(route('workspace.billing'))->assertRedirect(route('workspace.login'));
});

it('logs out a member the moment their account is suspended', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    app(Accounts::class)->suspend($account->id);

    // A live session no longer resolves — every guarded page bounces to login.
    $this->withSession([AccountAuth::SESSION_KEY => $owner->id])
        ->get(route('workspace.home'))
        ->assertRedirect(route('workspace.login'));
});

it('redirects a member who cannot read billing away from it', function (): void {
    ['account' => $account] = provisionAccount();
    // A Developer is a technical role — no billing, no member roster.
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev-billing@acme.example');

    $this->withSession([AccountAuth::SESSION_KEY => $dev->id])
        ->get(route('workspace.billing'))->assertRedirect(route('workspace.home'));
    $this->withSession([AccountAuth::SESSION_KEY => $dev->id])
        ->get(route('workspace.members'))->assertRedirect(route('workspace.home'));

    // A read-only Viewer, by contrast, may read both.
    $viewer = memberWithRole($account->id, AccountRole::Viewer, 'viewer-billing@acme.example');
    $this->withSession([AccountAuth::SESSION_KEY => $viewer->id])->get(route('workspace.billing'))->assertOk();
    $this->withSession([AccountAuth::SESSION_KEY => $viewer->id])->get(route('workspace.members'))->assertOk();
});

it('shows a scoped member only the environments they are granted', function (): void {
    ['account' => $account, 'project' => $project] = provisionAccount();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $staging = app(AccountProvisioner::class)->addEnvironment($project, 'Staging');

    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    app(AccountMembers::class)->setEnvironmentAccess($dev->id, all: false, environmentIds: [$staging->id]);

    // They see the project (it holds a reachable env)…
    $this->withSession([AccountAuth::SESSION_KEY => $dev->id])
        ->get(route('workspace.home'))->assertOk()->assertSee('Acme');

    // …and inside it, only their granted environment — production is outside the grant.
    $this->withSession([AccountAuth::SESSION_KEY => $dev->id])
        ->get(route('workspace.projects.show', $project->id))
        ->assertOk()
        ->assertSee('acme-staging.cboxid.com')
        ->assertDontSee('https://acme.cboxid.com');
});

it('lets a manager mint an API key and shows the plaintext once', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    session()->put(AccountAuth::SESSION_KEY, $owner->id);
    app(WorkspaceSudo::class)->confirm();

    $component = Volt::test('workspace.api-keys')
        ->set('newKeyName', 'CI deploy')
        ->set('newKeyRole', 'developer')
        ->call('createKey')
        ->assertHasNoErrors();

    expect($component->get('freshKey'))->toStartWith('cbid_acc_')
        ->and(app(AccountApiKeys::class)->forAccount($account->id))->toHaveCount(1);
});

it('redirects a non-manager away from API keys', function (): void {
    ['account' => $account] = provisionAccount();
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');

    $this->withSession([AccountAuth::SESSION_KEY => $dev->id])
        ->get(route('workspace.api-keys'))
        ->assertRedirect(route('workspace.home'));
});

it('lets an owner remove a member and transfer ownership', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    $admin = memberWithRole($account->id, AccountRole::Admin, 'admin@acme.example');
    session()->put(AccountAuth::SESSION_KEY, $owner->id);
    $members = app(AccountMembers::class);

    Volt::test('workspace.members')->call('removeMember', $dev->id);
    expect($members->find($dev->id))->toBeNull();

    Volt::test('workspace.members')->call('makeOwner', $admin->id);
    expect($members->find($admin->id)->role)->toBe(AccountRole::Owner)
        ->and($members->find($owner->id)->role)->toBe(AccountRole::Admin);
});

it('scopes a member to specific environments via the access editor', function (): void {
    ['account' => $account, 'member' => $owner, 'project' => $project] = provisionAccount();
    $staging = app(AccountProvisioner::class)->addEnvironment($project, 'Staging');
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    session()->put(AccountAuth::SESSION_KEY, $owner->id);

    Volt::test('workspace.members')
        ->call('manageAccess', $dev->id)
        ->assertSet('accessAll', true)
        ->set('accessAll', false)
        ->set('accessEnvIds', [$staging->id])
        ->call('saveAccess')
        ->assertSet('editingAccessFor', null);

    $members = app(AccountMembers::class);
    expect($members->accessibleEnvironmentIds($members->find($dev->id)))->toBe([$staging->id]);
});

it('renames the account from settings and redirects non-managers', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    session()->put(AccountAuth::SESSION_KEY, $owner->id);

    Volt::test('workspace.settings')->set('name', 'Renamed Co')->call('save')->assertHasNoErrors();
    expect(app(Accounts::class)->find($account->id)->name)->toBe('Renamed Co');

    // A developer can't reach settings.
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    $this->withSession([AccountAuth::SESSION_KEY => $dev->id])
        ->get(route('workspace.settings'))->assertRedirect(route('workspace.home'));
});

it('shows a read-only viewer the roster but not the invite form', function (): void {
    ['account' => $account] = provisionAccount();
    $viewer = memberWithRole($account->id, AccountRole::Viewer, 'viewer@acme.example');

    $this->withSession([AccountAuth::SESSION_KEY => $viewer->id])
        ->get(route('workspace.members'))
        ->assertOk()
        ->assertSee('Members')
        ->assertDontSee('Invite a teammate');
});

it('adds an environment to a project up to its plan limit, then refuses', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    session()->put(AccountAuth::SESSION_KEY, $member->id);

    // The project's limit is 2, one used → adding one succeeds.
    Volt::test('workspace.projects.show', ['project' => $project->id])
        ->set('newEnvironment', 'Staging')
        ->call('addEnvironment')
        ->assertHasNoErrors();

    expect(Environment::query()->where('project_id', $project->id)->count())->toBe(2);

    // The third is refused by the plan, with a friendly error rather than a throw.
    Volt::test('workspace.projects.show', ['project' => $project->id])
        ->set('newEnvironment', 'Dev')
        ->call('addEnvironment')
        ->assertHasErrors('newEnvironment');

    expect(Environment::query()->where('project_id', $project->id)->count())->toBe(2);
});

it('refuses a scoped member trying to add an environment to a project', function (): void {
    ['account' => $account, 'project' => $project] = provisionAccount();
    $staging = app(AccountProvisioner::class)->addEnvironment($project, 'Staging');
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    // Restrict the Developer to staging only — they can VIEW the project but must not
    // manage it (the env-add form is hidden; the server must refuse a direct call too).
    app(AccountMembers::class)->setEnvironmentAccess($dev->id, all: false, environmentIds: [$staging->id]);
    session()->put(AccountAuth::SESSION_KEY, $dev->id);

    Volt::test('workspace.projects.show', ['project' => $project->id])
        ->set('newEnvironment', 'Sneaky')
        ->call('addEnvironment')
        ->assertForbidden();

    expect(Environment::query()->where('project_id', $project->id)->count())->toBe(2);
});

it('suspends and reactivates a project', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    session()->put(AccountAuth::SESSION_KEY, $member->id);

    Volt::test('workspace.projects.show', ['project' => $project->id])->call('suspend');
    expect(Project::query()->whereKey($project->id)->value('status'))->toBe('suspended');

    Volt::test('workspace.projects.show', ['project' => $project->id])->call('reactivate');
    expect(Project::query()->whereKey($project->id)->value('status'))->toBe('active');
});

it('lets a member create a second project and drills into it empty', function (): void {
    ['member' => $member] = provisionAccount();
    session()->put(AccountAuth::SESSION_KEY, $member->id);

    Volt::test('workspace.projects.create')
        ->set('name', 'Product Two')
        ->call('create')
        ->assertHasNoErrors();

    $project = Project::query()->where('name', 'Product Two')->firstOrFail();

    // A brand-new project starts with no environments — the member adds them there.
    $this->withSession([AccountAuth::SESSION_KEY => $member->id])
        ->get(route('workspace.projects.show', $project->id))
        ->assertOk()
        ->assertSee('Product Two')
        ->assertSee('No environments yet');
});
