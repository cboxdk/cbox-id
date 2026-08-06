<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

beforeEach(function (): void {
    // These render product pages, which presuppose an installed deployment.
    installedDeployment();
});

/** Invite + activate a member with a role, returning them signed-in-ready. */
if (! function_exists('memberWithRole')) {
    function memberWithRole(string $accountId, MembershipRole $role, string $email): Membership
    {
        $members = app(Memberships::class);
        $m = $members->invite($accountId, $email, $role);
        $members->activate($m->id, 'a-strong-unbreached-passphrase');

        return $members->find($m->id);
    }
}

if (! function_exists('provisionAccount')) {
    /**
     * Provision an account and return its member/account/project/environment.
     *
     * @return array{member: Membership, account: Account, project: Project, environment: Environment}
     */
    function provisionAccount(string $email = 'owner@acme.example'): array
    {
        // The platform root FIRST. An account provisioned without one is in the
        // first-install bootstrap window: its members have no subject, and a member
        // with no subject has nothing to sign in.
        platformRootEnvironment();

        $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
            accountName: 'Acme',
            ownerEmail: $email,
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        return ['member' => $result->member, 'account' => $result->account, 'project' => $result->project, 'environment' => $result->environment];
    }
}

it('renders the console sign-in for guests', function (): void {
    // The ONE door. There used to be a second one at `/workspace/login` that said
    // "Sign in to your workspace"; it authenticated the same subject against the same
    // credential and is gone.
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Sign in');
});

it('redirects guests away from the projects page', function (): void {
    $this->get(route('projects'))->assertRedirect(route('login'));
});

it('remembers the intended destination when a guest hits open-environment (handoff round-trip)', function (): void {
    ['environment' => $environment] = provisionAccount();

    // A guest bounced here from a tenant admin console must, after signing in, land
    // back on the mint step — so the intended URL is stashed for redirect()->intended().
    $this->get(route('environment.open', $environment->id))
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', route('environment.open', $environment->id));
});

it('renders the workspace home with the account\'s projects', function (): void {
    ['member' => $member] = provisionAccount();

    // Home is the Projects launchpad — the account's default project card, with its
    // environment count.
    signInAsMember($member);
    $this->get(route('projects'))
        ->assertOk()
        ->assertSee('Projects')
        ->assertSee('Acme')          // the default project is named after the account
        ->assertSee('1 of 2');       // 1 of 2 environments
});

it('links each environment out to its own host-resolved URL on the project detail', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $staging = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');

    // The project detail lists each environment as a link to its own
    // {slug}.{base_domain} host — no session "current environment" is pinned.
    signInAsMember($member);
    $this->get(route('projects.show', $project->id))
        ->assertOk()
        ->assertSee('https://acme.cboxid.com')
        ->assertSee('https://'.$staging->slug.'.cboxid.com');
});

it('renders the members roster with the signed-in member marked', function (): void {
    ['member' => $member] = provisionAccount('dana@acme.example');

    signInAsMember($member);
    $this->get(route('account-members'))
        ->assertOk()
        ->assertSee('Account members')
        ->assertSee('dana@acme.example')
        ->assertSee('You');
});

it('renders billing with the real environment allowance', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    app(TenantProvisioner::class)->addEnvironment($project, 'Staging');

    signInAsMember($member);
    $this->get(route('billing'))
        ->assertOk()
        ->assertSee('Billing')
        // 2 of 2 environments used — real figures, no fabricated usage.
        ->assertSee('2 of 2')
        ->assertSee('How pricing works');
});

it('guards the members and billing pages behind the account session', function (): void {
    $this->get(route('account-members'))->assertRedirect(route('login'));
    $this->get(route('billing'))->assertRedirect(route('login'));
});

it('takes the Identity platform away the moment an account is suspended', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    app(Accounts::class)->suspend($account->id, $owner->id);

    // The suspension lands on the very next request, not at the next sign-in — that part
    // is unchanged. What changed is the refusal: this used to bounce to a sign-in the
    // person had already completed, because the account plane's gate was the only thing
    // standing between them and a console. They are an ordinary subject of the root and
    // still signed in; what a suspension takes away is the area, not the console.
    signInAsMember($owner);
    $this->get(route('projects'))
        ->assertRedirect(route('dashboard'));
});

it('redirects a member who cannot read billing away from it', function (): void {
    ['account' => $account] = provisionAccount();
    // A Developer is a technical role — no billing, no member roster.
    $dev = memberWithRole($account->id, MembershipRole::Developer, 'dev-billing@acme.example');

    signInAsMember($dev);
    $this->get(route('billing'))->assertRedirect(route('projects'));
    signInAsMember($dev);
    $this->get(route('account-members'))->assertRedirect(route('projects'));

    // A read-only Viewer, by contrast, may read both.
    $viewer = memberWithRole($account->id, MembershipRole::Viewer, 'viewer-billing@acme.example');
    signInAsMember($viewer);
    $this->get(route('billing'))->assertOk();
    signInAsMember($viewer);
    $this->get(route('account-members'))->assertOk();
});

it('shows a scoped member only the environments they are granted', function (): void {
    ['account' => $account, 'project' => $project] = provisionAccount();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $staging = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');

    $dev = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');
    app(Memberships::class)->setEnvironmentAccess($dev->id, all: false, environmentIds: [$staging->id]);

    // They see the project (it holds a reachable env)…
    signInAsMember($dev);
    $this->get(route('projects'))->assertOk()->assertSee('Acme');

    // …and inside it, only their granted environment — production is outside the grant.
    signInAsMember($dev);
    $this->get(route('projects.show', $project->id))
        ->assertOk()
        ->assertSee('acme-staging.cboxid.com')
        ->assertDontSee('https://acme.cboxid.com');
});

it('lets a manager mint an API key and shows the plaintext once', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    signInAsMember($owner);
    app(Sudo::class)->confirm();

    $component = Volt::test('console.api-keys')
        ->set('newKeyName', 'CI deploy')
        ->set('newKeyRole', 'developer')
        ->call('createKey')
        ->assertHasNoErrors();

    // Read from view data, not get(): the plaintext key is a PROTECTED property so it is
    // never dehydrated into the wire snapshot. Asserting on get() would now pass on null.
    expect($component->viewData('freshKey'))->toStartWith('cbid_acc_')
        ->and(app(OrganizationApiKeys::class)->forOrganization($account->id))->toHaveCount(1);
});

it('redirects a non-manager away from API keys', function (): void {
    ['account' => $account] = provisionAccount();
    $dev = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');

    signInAsMember($dev);
    $this->get(route('api-keys'))
        ->assertRedirect(route('projects'));
});

it('lets an owner remove a member and transfer ownership', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    $dev = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');
    $admin = memberWithRole($account->id, MembershipRole::Admin, 'admin@acme.example');
    signInAsMember($owner);
    $members = app(Memberships::class);

    Volt::test('console.account-members')->call('removeMember', $dev->id);
    expect($members->find($dev->id))->toBeNull();

    Volt::test('console.account-members')->call('makeOwner', $admin->id);
    expect($members->find($admin->id)->role)->toBe(MembershipRole::Owner)
        ->and($members->find($owner->id)->role)->toBe(MembershipRole::Admin);
});

it('scopes a member to specific environments via the access editor', function (): void {
    ['account' => $account, 'member' => $owner, 'project' => $project] = provisionAccount();
    $staging = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');
    $dev = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');
    signInAsMember($owner);

    Volt::test('console.account-members')
        ->call('manageAccess', $dev->id)
        ->assertSet('accessAll', true)
        ->set('accessAll', false)
        ->set('accessEnvIds', [$staging->id])
        ->call('saveAccess')
        ->assertSet('editingAccessFor', null);

    $members = app(Memberships::class);
    expect($members->accessibleEnvironmentIds($members->find($dev->id)))->toBe([$staging->id]);
});

/*
|--------------------------------------------------------------------------
| The account fence on the roster's write actions
|--------------------------------------------------------------------------
|
| `Membership` carries no global scope and `Memberships::find()` is
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
function aRivalAccountsMember(): Membership
{
    $rival = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        accountName: 'Rival',
        ownerEmail: 'owner@rival.example',
        ownerName: 'Rival Owner',
        ownerPassword: 'another-strong-passphrase',
    ));

    // A Developer rather than the rival's owner: the owner rule would refuse this target
    // even with the fence removed, and the test would pass for the wrong reason.
    return memberWithRole($rival->account->id, MembershipRole::Developer, 'dev@rival.example');
}

it('404s a role change aimed at another account member', function (): void {
    ['member' => $owner] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($owner);

    Volt::test('console.account-members')
        ->call('changeRole', $theirs->id, MembershipRole::Admin->value)
        ->assertStatus(404);

    expect(app(Memberships::class)->find($theirs->id)->role)->toBe(MembershipRole::Developer);
})->group('security');

it('404s a member removal aimed at another account', function (): void {
    ['member' => $owner] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($owner);

    Volt::test('console.account-members')->call('removeMember', $theirs->id)->assertStatus(404);

    expect(app(Memberships::class)->find($theirs->id))->not->toBeNull();
})->group('security');

it('404s an environment-access edit aimed at another account', function (): void {
    ['member' => $owner] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($owner);

    // The READ half. It opened the editor on somebody else's member and disclosed which
    // environments they reach.
    Volt::test('console.account-members')->call('manageAccess', $theirs->id)->assertStatus(404);
})->group('security');

it('404s an environment-access SAVE aimed at another account', function (): void {
    ['member' => $owner, 'project' => $project] = provisionAccount();
    $mine = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');
    $theirs = aRivalAccountsMember();
    signInAsMember($owner);

    // `editingAccessFor` is an ordinary public property, so the client sets it — the
    // write half never had to go through `manageAccess()` at all.
    Volt::test('console.account-members')
        ->set('editingAccessFor', $theirs->id)
        ->set('accessAll', false)
        ->set('accessEnvIds', [$mine->id])
        ->call('saveAccess')
        ->assertStatus(404);

    $members = app(Memberships::class);
    expect($members->find($theirs->id)->all_environments)->toBeTrue();
})->group('security');

it('404s an ownership transfer aimed at another account', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($owner);

    Volt::test('console.account-members')->call('makeOwner', $theirs->id)->assertStatus(404);

    $members = app(Memberships::class);
    expect($members->find($theirs->id)->role)->toBe(MembershipRole::Developer)
        // …and this account still has the owner it started with.
        ->and($members->find($owner->id)->role)->toBe(MembershipRole::Owner)
        ->and($members->find($theirs->id)->account_id)->not->toBe($account->id);
})->group('security');

it('renames the account from settings and redirects non-managers', function (): void {
    ['account' => $account, 'member' => $owner] = provisionAccount();
    signInAsMember($owner);

    Volt::test('console.account-settings')->set('name', 'Renamed Co')->call('save')->assertHasNoErrors();
    expect(app(Accounts::class)->find($account->id)->name)->toBe('Renamed Co');

    // A developer can't reach settings.
    $dev = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');
    signInAsMember($dev);
    $this->get(route('account-settings'))->assertRedirect(route('projects'));
});

it('shows a read-only viewer the roster but not the invite form', function (): void {
    ['account' => $account] = provisionAccount();
    $viewer = memberWithRole($account->id, MembershipRole::Viewer, 'viewer@acme.example');

    signInAsMember($viewer);
    $this->get(route('account-members'))
        ->assertOk()
        ->assertSee('Account members')
        ->assertDontSee('Invite a teammate');
});

it('adds an environment to a project up to its plan limit, then refuses', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    signInAsMember($member);

    // The project's limit is 2, one used → adding one succeeds.
    Volt::test('console.projects.show', ['project' => $project->id])
        ->set('newEnvironment', 'Staging')
        ->call('addEnvironment')
        ->assertHasNoErrors();

    expect(Environment::query()->where('project_id', $project->id)->count())->toBe(2);

    // The third is refused by the plan, with a friendly error rather than a throw.
    Volt::test('console.projects.show', ['project' => $project->id])
        ->set('newEnvironment', 'Dev')
        ->call('addEnvironment')
        ->assertHasErrors('newEnvironment');

    expect(Environment::query()->where('project_id', $project->id)->count())->toBe(2);
});

it('refuses a scoped member trying to add an environment to a project', function (): void {
    ['account' => $account, 'project' => $project] = provisionAccount();
    $staging = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');
    $dev = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');
    // Restrict the Developer to staging only — they can VIEW the project but must not
    // manage it (the env-add form is hidden; the server must refuse a direct call too).
    app(Memberships::class)->setEnvironmentAccess($dev->id, all: false, environmentIds: [$staging->id]);
    signInAsMember($dev);

    Volt::test('console.projects.show', ['project' => $project->id])
        ->set('newEnvironment', 'Sneaky')
        ->call('addEnvironment')
        ->assertForbidden();

    expect(Environment::query()->where('project_id', $project->id)->count())->toBe(2);
});

it('suspends and reactivates a project', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    signInAsMember($member);

    Volt::test('console.projects.show', ['project' => $project->id])->call('suspend');
    expect(Project::query()->whereKey($project->id)->value('status')?->value)->toBe('suspended');

    Volt::test('console.projects.show', ['project' => $project->id])->call('reactivate');
    expect(Project::query()->whereKey($project->id)->value('status')?->value)->toBe('active');
});

it('lets a member create a second project and drills into it empty', function (): void {
    ['member' => $member] = provisionAccount();
    signInAsMember($member);

    Volt::test('console.projects.create')
        ->set('name', 'Product Two')
        ->call('create')
        ->assertHasNoErrors();

    $project = Project::query()->where('name', 'Product Two')->firstOrFail();

    // A brand-new project starts with no environments — the member adds them there.
    signInAsMember($member);
    $this->get(route('projects.show', $project->id))
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
    $staging = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');

    signInAsMember($member);
    $this->get(route('projects'))
        ->assertOk()
        ->assertSee('Acme')
        // Both environments are on the launchpad itself...
        ->assertSee('Production')
        ->assertSee('Staging')
        // ...each with its own open link, plus the project's settings entry point.
        ->assertSee(route('environment.open', $staging->id), false)
        ->assertSee(route('projects.show', $project->id), false);
});

it('creates an environment inline from the launchpad', function (): void {
    ['member' => $member, 'project' => $project] = provisionAccount();
    signInAsMember($member);

    Volt::test('console.projects.index')
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
    $other = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        accountName: 'Rival',
        ownerEmail: 'owner@rival.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    signInAsMember($member);

    Volt::test('console.projects.index')
        ->call('startCreate', $other->project->id)
        ->set('newEnvironment', 'Sneaky')
        ->call('addEnvironment')
        ->assertStatus(404);

    expect(Environment::query()->where('project_id', $other->project->id)->where('name', 'Sneaky')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Who the area is for
|--------------------------------------------------------------------------
|
| The headline property of retiring `/workspace`: the pages are an AREA of the one
| console, present exactly where the acting organization owns identity providers. Not a
| plane, not a prefix, not a host — so these are asked of the rail the layout renders
| from, which is the thing a person actually sees.
*/

/** @return list<string> the Identity platform routes visible to whoever is signed in */
function identityPlatformPages(): array
{
    $area = collect(Console::nav()->areas())->firstWhere('key', 'identity-platform');

    return collect($area?->pages() ?? [])
        ->filter(fn ($page): bool => $page->feature === null || Console::featureActive($page->feature))
        ->map(fn ($page): string => $page->route)
        ->values()->all();
}

it('gives the whole area to an account owner', function (): void {
    ['member' => $member] = provisionAccount();
    signInAsMember($member);

    expect(identityPlatformPages())->toHaveCount(8);
});

it('gives a tenant of somebody else\'s IdP no area at all', function (): void {
    // A subject of a TENANT environment — the shape `acme.cboxid.com` serves. They
    // administer their own organization and own no identity providers, so there is
    // nothing here for them: the area holds no pages and the rail drops it, by the same
    // rule that already drops an area a module left empty.
    [$subjectId, $organization] = actingAsRole(MembershipRole::Owner);

    expect($subjectId)->not->toBe('')
        ->and($organization->slug)->not->toBe('')
        ->and(app(ConsoleScope::class)->accountRole())->toBeNull()
        ->and(identityPlatformPages())->toBe([]);

    // …and at the page, not only in the rail — a rail is not an authorization check. They
    // are an OWNER of their own organization, so this is the area refusing them rather
    // than the console.
    Volt::test('console.billing')->assertRedirect(route('projects'));
})->group('security');

it('takes the area away from an account with no organization at all', function (): void {
    ['member' => $member, 'account' => $account] = provisionAccount();

    // The state every account created before `accounts.organization_id` existed was in,
    // and the one the framework's 2026_08_05_000200 backfill now repairs. It is reproduced
    // by hand because no production path creates it any more: the installer stamps the
    // platform root inside the same transaction, ahead of the first account, and signup
    // only runs on an installed deployment.
    DB::table('accounts')->where('id', $account->id)->update(['organization_id' => null]);

    signInAsMember($member);

    // NULL, not the role. An earlier version returned the role here on the grounds that
    // there was no organization to compare against — which put the area in the rail on
    // every organization this person could act on, tenant hosts included. "We do not know
    // which organization this account owns" is not "all of them"; a broken row fails
    // closed.
    expect(app(ConsoleScope::class)->accountRole())->toBeNull()
        ->and(identityPlatformPages())->toBe([]);

    // And at the page too, because a rail is not an authorization check.
    Volt::test('console.billing')->assertRedirect(route('projects'));
})->group('security');

it('takes the area away when the acting organization is not the account\'s own', function (): void {
    ['member' => $member, 'account' => $account] = provisionAccount();

    // Provisioning homes the account in its own organization, which is the shape that
    // makes this test mean anything: there IS an organization to be acting on, and the
    // baseline is that acting on it gives the area.
    expect($account->refresh()->organization_id)->toBeString();

    signInAsMember($member);
    expect(app(ConsoleScope::class)->accountRole())->toBe(MembershipRole::Owner);

    // Now act on a DIFFERENT organization the same person belongs to. Their account role
    // has not changed and their projects have not moved; what changed is who the console
    // says they are acting for, and offering them their own billing under another
    // tenant's name in the chrome is the drift this predicate exists to stop.
    $other = app(Organizations::class)->create(new NewOrganization('Somebody Else', 'somebody-else'));
    app(Memberships::class)->add($other->id, (string) $member->subject_id, MembershipRole::Member);

    // In the ROOT's scope: an account member's subject and its session rows live there,
    // and under the suite's ambient environment the deny-by-default scope finds neither.
    $root = app(PlatformRoot::class);
    $subject = $root->run(fn () => app(Subjects::class)->find((string) $member->subject_id));
    $session = $root->run(fn () => app(SessionManager::class)->active((string) session(PlatformAuth::SESSION_KEY)));

    expect($subject)->not->toBeNull()->and($session)->not->toBeNull();

    session()->put(PlatformAuth::ORG_KEY, $other->id);
    app(CurrentUser::class)->set($subject, $session, app(Organizations::class)->find($other->id), MembershipRole::Member);

    expect(app(ConsoleScope::class)->accountRole())->toBeNull()
        ->and(identityPlatformPages())->toBe([]);
})->group('security');

/**
 * THE MATRIX, and the reason it exists.
 *
 * Every guard on this area now asks {@see OrganizationCapabilities} rather than the enum, so
 * the whole of the account plane's authorization is decided in one file — which is what
 * makes the Account→Organization fold a change to one file instead of thirty. The risk is
 * exactly proportional: a wrong extension there is wrong everywhere at once.
 *
 * Falsifying that seam by hand — rewriting `canManageEnvironments()` as
 * `MembershipRole::canWrite()`'s shape, which is the substitution the fold invites — turned
 * only TWO tests red. The seam was load-bearing and the net under it was not, so this pins
 * the whole verdict table instead: for each role, exactly which pages exist.
 *
 * It is written as data on purpose. The fold's acceptance criterion is that this table
 * reads identically before and after the source of truth changes from `MembershipRole` to a
 * membership — so it must be readable as a table, not inferred from a dozen scattered
 * assertions.
 */
it('shows exactly these pages to each account role', function (MembershipRole $role, array $expected): void {
    ['member' => $owner, 'account' => $account] = provisionAccount();

    // THROUGH `setRole()`, not by writing the row. A `forceFill` here passed for as long
    // as the member row was the authority, and stopped meaning anything the moment the
    // console started reading the membership — it produced a state the product cannot:
    // an account row saying Developer over a membership still saying Owner. Going through
    // the contract is what makes this table describe the deployment rather than the
    // fixture.
    //
    // A SECOND member, because the first is the account's only owner and demoting the last
    // owner is refused — which is the same reason ownership transfer promotes before it
    // demotes.
    $subject = $role === MembershipRole::Owner ? $owner : app(Memberships::class)->create(
        $account->id,
        'matrix-'.$role->value.'@acme.example',
        'a-strong-unbreached-passphrase',
        'Matrix',
    );

    if ($role !== MembershipRole::Owner) {
        app(Memberships::class)->setRole($subject->id, $role);
    }

    signInAsMember($subject->refresh());

    expect(identityPlatformPages())->toBe($expected, $role->value.' sees the wrong pages');
})->with([
    // Everything. Ownership itself is guarded per-action, not by hiding pages.
    'owner' => [MembershipRole::Owner, [
        'projects', 'account-members', 'api-keys', 'environment-keys',
        'environment-domains', 'account-activity', 'billing', 'account-settings',
    ]],

    // An admin is an owner minus the ownership transfer, which is not a page.
    'admin' => [MembershipRole::Admin, [
        'projects', 'account-members', 'api-keys', 'environment-keys',
        'environment-domains', 'account-activity', 'billing', 'account-settings',
    ]],

    // THE ONE THE FOLD BREAKS. A developer is a technical credential: environments and
    // their keys and domains, and NOT the member roster — `canReadMembers()` excludes them
    // by design, because a leaked developer key must not enumerate the team — and not
    // billing. `MembershipRole` has no read predicate at all, so a naive translation hands
    // both back.
    'developer' => [MembershipRole::Developer, [
        'projects', 'environment-keys', 'environment-domains',
    ]],

    // NO BILLING ROW, and its absence is the finding.
    //
    // It used to be `['projects', 'billing']` — the plan and nothing else, in particular
    // not the roster. Once the capability came from the membership the same member saw
    // `account-members` and `account-activity` too, because Billing maps to Viewer and a
    // Viewer may read the roster. That is a widening of access to PII, and no organization
    // role both reads the plan and refuses the roster, so the mapping cannot be made
    // faithful.
    //
    // The role is therefore no longer assignable (laravel-id 0.91.1), which makes this
    // row unreachable rather than merely wrong. `AccountAndMembershipRoleMappingTest` in
    // the framework pins the exclusion and the reason.

    // Read-only across the account: it may SEE the roster and the bill, and change
    // neither — so no API keys, no environment keys or domains, no settings.
    'viewer' => [MembershipRole::Viewer, [
        'projects', 'account-members', 'account-activity', 'billing',
    ]],
])->group('security');

/**
 * WHICH ROW DECIDES — the whole of batch 3 in one assertion.
 *
 * The two agree by construction: `MembershipRole::asMembershipRole()` is the one mapping and
 * `DatabaseMemberships::setRole()` carries every change onto the membership. That is
 * what made the flip a non-change, and it is also what makes the flip invisible to every
 * other test in this file — agreement proves nothing about which one is being read.
 *
 * So this makes them disagree, by writing the membership behind the contract's back, and
 * asserts the console follows the MEMBERSHIP. An account is an organization; a person's
 * authority over an organization is their membership of it; and the member row is a
 * lookup saying which account a subject belongs to, not what they may do there.
 */
it('follows the membership when the member row disagrees', function (): void {
    ['member' => $owner, 'account' => $account] = provisionAccount();

    $member = app(Memberships::class)->create(
        $account->id,
        'disagreement@acme.example',
        'a-strong-unbreached-passphrase',
        'Disagreement',
    );
    app(Memberships::class)->setRole($member->id, MembershipRole::Admin);

    signInAsMember($member->refresh());
    expect(app(ConsoleScope::class)->capabilities()?->canManageMembers())->toBeTrue();

    // Behind the contract's back, so the member row still says Admin. Nothing in the
    // product does this — which is the point: it isolates the question.
    app(PlatformRoot::class)->run(fn () => DB::table('memberships')
        ->where('organization_id', $account->refresh()->organization_id)
        ->where('user_id', $member->subject_id)
        ->update(['role' => MembershipRole::Viewer->value]));

    nextRequest();
    signInAsMember($member->refresh());

    expect($member->refresh()->role)->toBe(MembershipRole::Admin, 'fixture: the member row must still disagree')
        ->and(app(ConsoleScope::class)->capabilities()?->canManageMembers())->toBeFalse()
        ->and(identityPlatformPages())->not->toContain('account-settings');

    expect($owner->refresh()->role)->toBe(MembershipRole::Owner);
})->group('security');

/**
 * ONE ROSTER, and `/members` is not it.
 *
 * An account IS an organization in the platform root, so on that host this page and
 * `/members` list the same people. They wrote different columns:
 * `/account-members` writes `account_members.role` and syncs it onto the membership;
 * `/members` writes `memberships.role` and syncs nothing back. The console reads the
 * membership and ten write guards read the member row, so the two disagreed the moment
 * either was used on somebody the other owned.
 *
 * Both directions were reachable and both were wrong:
 *
 *  - UP. Re-role a Developer to Admin from `/members` and they gain the member roster,
 *    the account activity chain and billing — all three of which `MembershipRole::Developer`
 *    refuses by design, because a leaked developer credential must not enumerate the team.
 *    Nothing recorded it on the account plane; `/account-members` still rendered them as
 *    "Developer".
 *  - DOWN. Re-role them to `Member` and the rail goes dark, so it looks like it worked —
 *    while `projects/create` and the environment handoff, which read the member row, still
 *    let them stand up an environment and open a live environment-admin session on a
 *    tenant host. A demotion that confirms itself and demotes nothing.
 *
 * The fence is a refusal rather than a two-way sync, because syncing back would first
 * require deciding what `MembershipRole::Member` and `Owner` mean on the account plane —
 * today "nothing" and "not assignable".
 */
it('refuses to re-role an account member from the organization roster', function (): void {
    ['member' => $owner, 'account' => $account] = provisionAccount();

    $target = app(Memberships::class)->create($account->id, 'dev@acme.example', 'a-strong-unbreached-passphrase', 'Dev');
    app(Memberships::class)->setRole($target->id, MembershipRole::Developer);

    signInAsMember($owner);

    Volt::test('members')->call('setRole', (string) $target->refresh()->subject_id, MembershipRole::Admin->value);

    // BOTH rows, because the whole defect was that they could disagree.
    expect($target->refresh()->role)->toBe(MembershipRole::Developer, 'the account role moved from the wrong page')
        ->and(app(PlatformRoot::class)->run(
            fn () => app(Memberships::class)->of((string) $account->refresh()->organization_id, (string) $target->subject_id)?->role,
        ))->toBe(MembershipRole::Developer, 'the membership moved without the account role');
})->group('security');

it('refuses to remove an account member from the organization roster', function (): void {
    ['member' => $owner, 'account' => $account] = provisionAccount();

    $target = app(Memberships::class)->create($account->id, 'gone@acme.example', 'a-strong-unbreached-passphrase', 'Gone');

    signInAsMember($owner);

    // Asserted on the REFUSAL, not on the surviving membership.
    //
    // The first version checked that the membership was still there — and it passed with
    // the fence deleted, because several other things in `remove()` can also leave it
    // standing. A test that cannot tell "refused" from "did nothing" is not a test of a
    // refusal. The toast is dispatched by the fence and by nothing else.
    Volt::test('members')
        ->call('remove', (string) $target->refresh()->subject_id)
        ->assertDispatched('toast', fn (string $event, array $params): bool => str_contains(
            (string) ($params['message'] ?? ''),
            'Account members',
        ));

    // …and the membership is still there, which is the outcome that matters: removing it
    // alone would leave an account member with no place in the organization their own
    // account owns — the exact state 2026_08_05_000200 repaired.
    expect(app(PlatformRoot::class)->run(
        fn () => app(Memberships::class)->of((string) $account->refresh()->organization_id, (string) $target->subject_id),
    ))->not->toBeNull('the account member lost their place in their own account\'s organization');
})->group('security');
