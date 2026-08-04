<?php

declare(strict_types=1);

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

beforeEach(function (): void {
    // These render product pages, which presuppose an installed deployment.
    installedDeployment();
});

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
        // The platform root FIRST. An account provisioned without one is in the
        // first-install bootstrap window: its members have no subject, and a member
        // with no subject has nothing to sign in.
        platformRootEnvironment();

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
    signInAsMember($member);
    $this->get(route('workspace.home'))
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
    signInAsMember($member);
    $this->get(route('workspace.projects.show', $project->id))
        ->assertOk()
        ->assertSee('https://acme.cboxid.com')
        ->assertSee('https://'.$staging->slug.'.cboxid.com');
});

it('renders the members roster with the signed-in member marked', function (): void {
    ['member' => $member] = provisionAccount('dana@acme.example');

    signInAsMember($member);
    $this->get(route('workspace.members'))
        ->assertOk()
        ->assertSee('Members')
        ->assertSee('dana@acme.example')
        ->assertSee('You');
});

it('renders billing with the real environment allowance', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    app(AccountProvisioner::class)->addEnvironment($project, 'Staging');

    signInAsMember($member);
    $this->get(route('workspace.billing'))
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
    app(Accounts::class)->suspend($account->id, $owner->id);

    // A live session no longer resolves — every guarded page bounces to login.
    signInAsMember($owner);
    $this->get(route('workspace.home'))
        ->assertRedirect(route('workspace.login'));
});

it('redirects a member who cannot read billing away from it', function (): void {
    ['account' => $account] = provisionAccount();
    // A Developer is a technical role — no billing, no member roster.
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev-billing@acme.example');

    signInAsMember($dev);
    $this->get(route('workspace.billing'))->assertRedirect(route('workspace.home'));
    signInAsMember($dev);
    $this->get(route('workspace.members'))->assertRedirect(route('workspace.home'));

    // A read-only Viewer, by contrast, may read both.
    $viewer = memberWithRole($account->id, AccountRole::Viewer, 'viewer-billing@acme.example');
    signInAsMember($viewer);
    $this->get(route('workspace.billing'))->assertOk();
    signInAsMember($viewer);
    $this->get(route('workspace.members'))->assertOk();
});

it('shows a scoped member only the environments they are granted', function (): void {
    ['account' => $account, 'project' => $project] = provisionAccount();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $staging = app(AccountProvisioner::class)->addEnvironment($project, 'Staging');

    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    app(AccountMembers::class)->setEnvironmentAccess($dev->id, all: false, environmentIds: [$staging->id]);

    // They see the project (it holds a reachable env)…
    signInAsMember($dev);
    $this->get(route('workspace.home'))->assertOk()->assertSee('Acme');

    // …and inside it, only their granted environment — production is outside the grant.
    signInAsMember($dev);
    $this->get(route('workspace.projects.show', $project->id))
        ->assertOk()
        ->assertSee('acme-staging.cboxid.com')
        ->assertDontSee('https://acme.cboxid.com');
});

it('lets a manager mint an API key and shows the plaintext once', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    signInAsMember($owner);
    app(WorkspaceSudo::class)->confirm();

    $component = Volt::test('workspace.api-keys')
        ->set('newKeyName', 'CI deploy')
        ->set('newKeyRole', 'developer')
        ->call('createKey')
        ->assertHasNoErrors();

    // Read from view data, not get(): the plaintext key is a PROTECTED property so it is
    // never dehydrated into the wire snapshot. Asserting on get() would now pass on null.
    expect($component->viewData('freshKey'))->toStartWith('cbid_acc_')
        ->and(app(AccountApiKeys::class)->forAccount($account->id))->toHaveCount(1);
});

it('redirects a non-manager away from API keys', function (): void {
    ['account' => $account] = provisionAccount();
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');

    signInAsMember($dev);
    $this->get(route('workspace.api-keys'))
        ->assertRedirect(route('workspace.home'));
});

it('lets an owner remove a member and transfer ownership', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    $admin = memberWithRole($account->id, AccountRole::Admin, 'admin@acme.example');
    signInAsMember($owner);
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
    signInAsMember($owner);

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

/*
|--------------------------------------------------------------------------
| The account fence on the roster's write actions
|--------------------------------------------------------------------------
|
| `AccountMember` carries no global scope and `AccountMembers::find()` is
| deliberately global — it is what answers "which account is this person on" at
| the root — so every one of these actions used to resolve its target across
| every account on the install and fence it with an `if` afterwards. The
| comparison held, but it is the shape that shipped a cross-organization IDOR on
| /governance/{campaign}: a page that resolves on the primary key and re-checks
| after. The account id belongs in the query.
|
| Each action gets its own deep link because each is its own entry point: they
| share a resolver TODAY, and a test per action is what notices when one of them
| stops using it.
*/

/** An admin of Acme, and a Developer on a rival account they may not touch. */
function aRivalAccountsMember(): AccountMember
{
    $rival = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Rival',
        ownerEmail: 'owner@rival.example',
        ownerName: 'Rival Owner',
        ownerPassword: 'another-strong-passphrase',
    ));

    // A Developer rather than the rival's owner: the owner rule would refuse this target
    // even with the fence removed, and the test would pass for the wrong reason.
    return memberWithRole($rival->account->id, AccountRole::Developer, 'dev@rival.example');
}

it('404s a role change aimed at another account member', function (): void {
    ['member' => $owner] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($owner);

    Volt::test('workspace.members')
        ->call('changeRole', $theirs->id, AccountRole::Admin->value)
        ->assertStatus(404);

    expect(app(AccountMembers::class)->find($theirs->id)->role)->toBe(AccountRole::Developer);
})->group('security');

it('404s a member removal aimed at another account', function (): void {
    ['member' => $owner] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($owner);

    Volt::test('workspace.members')->call('removeMember', $theirs->id)->assertStatus(404);

    expect(app(AccountMembers::class)->find($theirs->id))->not->toBeNull();
})->group('security');

it('404s an environment-access edit aimed at another account', function (): void {
    ['member' => $owner] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($owner);

    // The READ half. It opened the editor on somebody else's member and disclosed which
    // environments they reach.
    Volt::test('workspace.members')->call('manageAccess', $theirs->id)->assertStatus(404);
})->group('security');

it('404s an environment-access SAVE aimed at another account', function (): void {
    ['member' => $owner, 'project' => $project] = provisionAccount();
    $mine = app(AccountProvisioner::class)->addEnvironment($project, 'Staging');
    $theirs = aRivalAccountsMember();
    signInAsMember($owner);

    // `editingAccessFor` is an ordinary public property, so the client sets it — the
    // write half never had to go through `manageAccess()` at all.
    Volt::test('workspace.members')
        ->set('editingAccessFor', $theirs->id)
        ->set('accessAll', false)
        ->set('accessEnvIds', [$mine->id])
        ->call('saveAccess')
        ->assertStatus(404);

    $members = app(AccountMembers::class);
    expect($members->find($theirs->id)->all_environments)->toBeTrue();
})->group('security');

it('404s an ownership transfer aimed at another account', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($owner);

    Volt::test('workspace.members')->call('makeOwner', $theirs->id)->assertStatus(404);

    $members = app(AccountMembers::class);
    expect($members->find($theirs->id)->role)->toBe(AccountRole::Developer)
        // …and this account still has the owner it started with.
        ->and($members->find($owner->id)->role)->toBe(AccountRole::Owner)
        ->and($members->find($theirs->id)->account_id)->not->toBe($account->id);
})->group('security');

it('renames the account from settings and redirects non-managers', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    signInAsMember($owner);

    Volt::test('workspace.settings')->set('name', 'Renamed Co')->call('save')->assertHasNoErrors();
    expect(app(Accounts::class)->find($account->id)->name)->toBe('Renamed Co');

    // A developer can't reach settings.
    $dev = memberWithRole($account->id, AccountRole::Developer, 'dev@acme.example');
    signInAsMember($dev);
    $this->get(route('workspace.settings'))->assertRedirect(route('workspace.home'));
});

it('shows a read-only viewer the roster but not the invite form', function (): void {
    ['account' => $account] = provisionAccount();
    $viewer = memberWithRole($account->id, AccountRole::Viewer, 'viewer@acme.example');

    signInAsMember($viewer);
    $this->get(route('workspace.members'))
        ->assertOk()
        ->assertSee('Members')
        ->assertDontSee('Invite a teammate');
});

it('adds an environment to a project up to its plan limit, then refuses', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    signInAsMember($member);

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
    signInAsMember($dev);

    Volt::test('workspace.projects.show', ['project' => $project->id])
        ->set('newEnvironment', 'Sneaky')
        ->call('addEnvironment')
        ->assertForbidden();

    expect(Environment::query()->where('project_id', $project->id)->count())->toBe(2);
});

it('suspends and reactivates a project', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    signInAsMember($member);

    Volt::test('workspace.projects.show', ['project' => $project->id])->call('suspend');
    expect(Project::query()->whereKey($project->id)->value('status')?->value)->toBe('suspended');

    Volt::test('workspace.projects.show', ['project' => $project->id])->call('reactivate');
    expect(Project::query()->whereKey($project->id)->value('status')?->value)->toBe('active');
});

it('lets a member create a second project and drills into it empty', function (): void {
    ['member' => $member] = provisionAccount();
    signInAsMember($member);

    Volt::test('workspace.projects.create')
        ->set('name', 'Product Two')
        ->call('create')
        ->assertHasNoErrors();

    $project = Project::query()->where('name', 'Product Two')->firstOrFail();

    // A brand-new project starts with no environments — the member adds them there.
    signInAsMember($member);
    $this->get(route('workspace.projects.show', $project->id))
        ->assertOk()
        ->assertSee('Product Two')
        ->assertSee('No environments yet');
});

/**
 * The launchpad lists environments UNDER their project, so opening one — the thing
 * people actually come here for — is a single click rather than a drill-down through
 * a project page first.
 */
it('lists every environment grouped under its project, each with an Open link', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    $staging = app(AccountProvisioner::class)->addEnvironment($project, 'Staging');

    signInAsMember($member);
    $this->get(route('workspace.home'))
        ->assertOk()
        ->assertSee('Acme')
        // Both environments are on the launchpad itself...
        ->assertSee('Production')
        ->assertSee('Staging')
        // ...each with its own open link, plus the project's settings entry point.
        ->assertSee(route('workspace.environment.open', $staging->id), false)
        ->assertSee(route('workspace.projects.show', $project->id), false);
});

it('creates an environment inline from the launchpad', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    signInAsMember($member);

    Volt::test('workspace.home')
        ->call('startCreate', $project->id)
        ->set('newEnvironment', 'Staging')
        ->set('newEnvironmentType', 'sandbox')
        ->call('addEnvironment')
        ->assertHasNoErrors();

    expect(Environment::query()->where('project_id', $project->id)->where('name', 'Staging')->exists())->toBeTrue();
});

// The inline form is a convenience, never a second authorization path.
it('refuses an inline environment create for a project on another account', function (): void {
    ['member' => $member] = provisionAccount();
    $other = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Rival',
        ownerEmail: 'owner@rival.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    signInAsMember($member);

    Volt::test('workspace.home')
        ->call('startCreate', $other->project->id)
        ->set('newEnvironment', 'Sneaky')
        ->call('addEnvironment')
        ->assertStatus(404);

    expect(Environment::query()->where('project_id', $other->project->id)->where('name', 'Sneaky')->exists())->toBeFalse();
});
