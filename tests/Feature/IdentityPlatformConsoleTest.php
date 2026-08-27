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
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    // These render product pages, which presuppose an installed deployment.
    installedDeployment();
});

/**
 * Add a member with a role, returning them ready to sign in.
 *
 * DIRECTLY, not through an invitation. The account plane's invite created a member row in
 * an `invited` state that `activate()` then turned real, so "a member with role X" and "an
 * invitation" were the same fixture. They are two records now, and what these tests need is
 * the first: a subject who holds a membership. Routing them through an invitation would be
 * testing the invitation flow in every page test that wants a viewer.
 *
 * @return array{0: Membership, 1: string} the membership, and the subject id to sign in as
 */
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
    ['member' => $member, 'subjectId' => $memberSubjectId] = provisionAccount();

    // Home is the Projects launchpad — the account's default project card, with its
    // environment count.
    signInAsMember($memberSubjectId);
    $this->get(route('projects'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/projects/index')
            // The default project is named after the account, and carries its real
            // environment count against the plan's allowance.
            ->where('projects.0.name', 'Acme')
            ->where('projects.0.used', 1)
            ->where('projects.0.limit', 2));
});

it('links each environment out to its own host-resolved URL on the project detail', function (): void {
    ['member' => $member, 'subjectId' => $memberSubjectId, 'project' => $project] = provisionAccount();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $staging = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');

    // The project detail lists each environment as a link to its own
    // {slug}.{base_domain} host — no session "current environment" is pinned.
    signInAsMember($memberSubjectId);
    $this->get(route('projects.show', $project->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/projects/show')
            ->where('environments', fn (Collection $environments): bool => $environments
                ->pluck('url')
                ->all() === ['https://acme.cboxid.com', 'https://'.$staging->slug.'.cboxid.com']));
});

it('renders the members roster with the signed-in member marked', function (): void {
    ['member' => $member, 'subjectId' => $memberSubjectId] = provisionAccount('dana@acme.example');

    signInAsMember($memberSubjectId);
    $this->get(route('members'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/members')
            ->where('members.0.email', 'dana@acme.example')
            // "You" is drawn from this, and it is the property worth pinning: a roster
            // that cannot tell the reader which row is theirs is where somebody removes
            // their own access.
            ->where('members.0.isSelf', true));
});

it('renders billing with the real environment allowance', function (): void {
    ['member' => $member, 'subjectId' => $memberSubjectId, 'project' => $project] = provisionAccount();
    app(TenantProvisioner::class)->addEnvironment($project, 'Staging');

    signInAsMember($memberSubjectId);

    // REAL FIGURES, no fabricated usage: two environments against an allowance of two,
    // counted from the project's own environments. Asked of the row rather than of the
    // sentence "2 of 2", which the page could render from anything.
    $row = collect((array) $this->get(route('billing'))->assertOk()->inertiaProps('projects'))
        ->firstWhere('id', $project->id);

    expect($row['used'])->toBe(2)
        ->and($row['limit'])->toBe(2)
        // …and each row is a way INTO the project it bills for.
        ->and($row['href'])->toBe(route('projects.show', $project->id));
});

it('guards the members and billing pages behind the account session', function (): void {
    $this->get(route('members'))->assertRedirect(route('login'));
    $this->get(route('billing'))->assertRedirect(route('login'));
});

it('closes the console the moment a customer is suspended', function (): void {
    ['organization' => $account, 'member' => $owner, 'subjectId' => $ownerSubjectId] = provisionAccount();
    app(PlatformRoot::class)->run(fn () => app(Organizations::class)->suspend($account->id, $ownerSubjectId));

    // ON THE VERY NEXT REQUEST, not at the next sign-in — that part is unchanged, and it
    // is the half that matters most.
    //
    // WHAT THE SUSPENSION TAKES DID CHANGE, and it is worth stating rather than absorbing.
    // This used to assert that the console STAYED and only the Identity-platform area went:
    // a suspended ACCOUNT left its organization untouched, so the middleware's
    // suspended-organization rule never fired on the person and they landed on the
    // dashboard as an ordinary subject of the root.
    //
    // A customer IS an organization now, so suspending one trips exactly that rule — the
    // console refuses outright, with the reason. Nothing was loosened to get here and no
    // new gate was added: a rule that already existed, and was already right, now covers a
    // case it could not previously see. An off-switch that leaves the operator's own
    // console open to the customer they just switched off was the weaker reading.
    signInAsMember($ownerSubjectId);
    $this->get(route('projects'))
        ->assertForbidden();
});

it('redirects a member who cannot read billing away from it', function (): void {
    ['organization' => $account, 'subjectId' => $memberSubjectId] = provisionAccount();
    // A Developer is a technical role — no billing, no member roster.
    [$dev, $devSubjectId] = memberWithRole($account->id, MembershipRole::Developer, 'dev-billing@acme.example');

    signInAsMember($devSubjectId);
    $this->get(route('billing'))->assertRedirect(route('projects'));
    signInAsMember($devSubjectId);
    $this->get(route('members'))->assertRedirect(route('projects'));

    // A read-only Viewer, by contrast, may read both.
    [$viewer, $viewerSubjectId] = memberWithRole($account->id, MembershipRole::Viewer, 'viewer-billing@acme.example');
    signInAsMember($viewerSubjectId);
    $this->get(route('billing'))->assertOk();
    signInAsMember($viewerSubjectId);
    $this->get(route('members'))->assertOk();
});

it('shows a scoped member only the environments they are granted', function (): void {
    ['organization' => $account, 'subjectId' => $memberSubjectId, 'project' => $project] = provisionAccount();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $staging = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');

    [$dev, $devSubjectId] = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->setEnvironmentAccess($account->id, $devSubjectId, all: false, environmentIds: [$staging->id]));

    // They see the project (it holds a reachable env)…
    signInAsMember($devSubjectId);
    $this->get(route('projects'))->assertOk()->assertSee('Acme');

    // …and inside it, only their granted environment — production is outside the grant.
    signInAsMember($devSubjectId);
    $this->get(route('projects.show', $project->id))
        ->assertOk()
        ->assertSee('acme-staging.cboxid.com')
        ->assertDontSee('https://acme.cboxid.com');
});

it('lets a manager mint an API key and shows the plaintext once', function (): void {
    ['organization' => $account, 'member' => $owner, 'subjectId' => $ownerSubjectId] = provisionAccount();
    signInAsMember($ownerSubjectId);
    app(Sudo::class)->confirm();

    $this->from(route('api-keys'))
        ->post(route('api-keys.store'), ['name' => 'CI deploy', 'role' => 'developer'])
        ->assertSessionHasNoErrors()
        // ONCE, and on the flash channel: props are written into the history entry, so a
        // full-authority credential there is readable by pressing Back.
        ->assertInertiaFlash('freshKey');

    $flash = session()->get(SessionKey::FLASH_DATA, []);

    expect(is_array($flash) ? ($flash['freshKey'] ?? null) : null)->toStartWith('cbid_org_')
        ->and(app(OrganizationApiKeys::class)->forOrganization($account->id))->toHaveCount(1);

    // AND NOT AGAIN. The next render of the page carries no key at all — the flash is
    // one-shot, which is the whole reason it is the channel this uses.
    $this->get(route('api-keys'))->assertOk()->assertInertiaFlashMissing('freshKey');
});

it('redirects a non-manager away from API keys', function (): void {
    ['organization' => $account, 'subjectId' => $memberSubjectId] = provisionAccount();
    [$dev, $devSubjectId] = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');

    signInAsMember($devSubjectId);
    $this->get(route('api-keys'))
        ->assertRedirect(route('projects'));
});

it('lets an owner remove a member and transfer ownership', function (): void {
    ['organization' => $account, 'member' => $owner, 'subjectId' => $ownerSubjectId] = provisionAccount();
    [$dev, $devSubjectId] = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');
    [$admin, $adminSubjectId] = memberWithRole($account->id, MembershipRole::Admin, 'admin@acme.example');
    signInAsMember($ownerSubjectId);
    $members = app(Memberships::class);

    $this->delete(route('members.remove', $dev->id))->assertRedirect();
    expect(freshMembership($dev))->toBeNull();

    $this->post(route('members.transfer-ownership', $admin->id))->assertRedirect();
    expect(freshMembership($admin)->role)->toBe(MembershipRole::Owner)
        ->and(freshMembership($owner)->role)->toBe(MembershipRole::Admin);
});

it('scopes a member to specific environments via the access editor', function (): void {
    ['organization' => $account, 'member' => $owner, 'subjectId' => $ownerSubjectId, 'project' => $project] = provisionAccount();
    $staging = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');
    [$dev, $devSubjectId] = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');
    signInAsMember($ownerSubjectId);

    // The editor opens over the URL, so what it shows is a prop this test can read —
    // and what it shows is the grant as it stands, which is "everything" by default.
    $this->get(route('members', ['editing' => $dev->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('editor.all', true));

    $this->put(route('members.access', $dev->id), [
        'all' => false,
        'environmentIds' => [$staging->id],
    ])->assertRedirect();

    $members = app(Memberships::class);
    expect(app(PlatformRoot::class)->run(fn (): array => $members->accessibleEnvironmentIds($dev->organization_id, $dev->user_id)))->toBe([$staging->id]);
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

/** A Developer on a RIVAL customer, whom the acting admin may not touch. */
function aRivalAccountsMember(): Membership
{
    $rival = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Rival',
        ownerEmail: 'owner@rival.example',
        ownerName: 'Rival Owner',
        ownerPassword: 'another-strong-passphrase',
    ));

    // A Developer rather than the rival's owner: the owner rule would refuse this target
    // even with the fence removed, and the test would pass for the wrong reason.
    [$membership] = memberWithRole($rival->organization->id, MembershipRole::Developer, 'dev@rival.example');

    return $membership;
}

it('404s a role change aimed at another customer\'s member', function (): void {
    ['organization' => $account, 'member' => $owner, 'subjectId' => $ownerSubjectId] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($ownerSubjectId);

    $this->patch(route('members.role', $theirs->id), ['role' => MembershipRole::Admin->value])
        ->assertNotFound();

    expect(freshMembership($theirs)->role)->toBe(MembershipRole::Developer);
})->group('security');

it('404s a member removal aimed at another account', function (): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($ownerSubjectId);

    $this->delete(route('members.remove', $theirs->id))->assertNotFound();

    expect(freshMembership($theirs))->not->toBeNull();
})->group('security');

it('404s an environment-access edit aimed at another account', function (): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($ownerSubjectId);

    // The READ half. It opened the editor on somebody else's member and disclosed which
    // environments they reach — which is now a URL, so the refusal has to be on the page
    // that honours it rather than on an action nobody calls any more.
    $this->get(route('members', ['editing' => $theirs->id]))->assertNotFound();
})->group('security');

it('404s an environment-access SAVE aimed at another account', function (): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId, 'project' => $project] = provisionAccount();
    $mine = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');
    $theirs = aRivalAccountsMember();
    signInAsMember($ownerSubjectId);

    // The write half, asked directly. Opening the editor is a read the save does not
    // depend on, so refusing only the read would leave this reachable by anyone who can
    // form a URL.
    $this->put(route('members.access', $theirs->id), [
        'all' => false,
        'environmentIds' => [$mine->id],
    ])->assertNotFound();

    $members = app(Memberships::class);
    expect(freshMembership($theirs)->all_environments)->toBeTrue();
})->group('security');

it('404s an ownership transfer aimed at another customer', function (): void {
    ['organization' => $account, 'member' => $owner, 'subjectId' => $ownerSubjectId] = provisionAccount();
    $theirs = aRivalAccountsMember();
    signInAsMember($ownerSubjectId);

    $this->post(route('members.transfer-ownership', $theirs->id))->assertNotFound();

    $members = app(Memberships::class);
    expect(freshMembership($theirs)->role)->toBe(MembershipRole::Developer)
        // …and this account still has the owner it started with.
        ->and(freshMembership($owner)->role)->toBe(MembershipRole::Owner)
        ->and($theirs->organization_id)->not->toBe($account->id);
})->group('security');

it('renames the account from settings and redirects non-managers', function (): void {
    ['organization' => $account, 'member' => $owner, 'subjectId' => $ownerSubjectId] = provisionAccount();
    signInAsMember($ownerSubjectId);

    $this->from(route('organization-settings'))
        ->patch(route('organization-settings.update'), ['name' => 'Renamed Co'])
        ->assertSessionHasNoErrors();
    expect(app(PlatformRoot::class)->run(fn () => app(Organizations::class)->find($account->id))->name)->toBe('Renamed Co');

    // A developer can't reach settings.
    [$dev, $devSubjectId] = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');
    signInAsMember($devSubjectId);
    $this->get(route('organization-settings'))->assertRedirect(route('projects'));
});

it('shows a read-only viewer the roster but not the invite form', function (): void {
    ['organization' => $account, 'subjectId' => $memberSubjectId] = provisionAccount();
    [$viewer, $viewerSubjectId] = memberWithRole($account->id, MembershipRole::Viewer, 'viewer@acme.example');

    signInAsMember($viewerSubjectId);
    $this->get(route('members'))
        ->assertOk()
        // "Administrators" since the People area's tenant directory and this page stopped
        // sharing both a route and a label. This assertion is only confirming the page
        // rendered at all — the property under test is the pair below it: a Viewer reads
        // the roster and is offered no way to change it.
        ->assertSee('Administrators')
        ->assertSee($viewer->email ?? 'viewer@acme.example')
        ->assertDontSee('Invite a teammate');
});

it('adds an environment to a project up to its plan limit, then refuses', function (): void {
    ['member' => $member, 'subjectId' => $memberSubjectId, 'project' => $project] = provisionAccount();
    signInAsMember($memberSubjectId);

    // The project's limit is 2, one used → adding one succeeds.
    $this->post(route('projects.environments.store', $project->id), [
        'name' => 'Staging',
        'type' => 'production',
    ])->assertSessionHasNoErrors();

    expect(Environment::query()->where('project_id', $project->id)->count())->toBe(2);

    // The third is refused by the plan, with a friendly error rather than a throw.
    $this->post(route('projects.environments.store', $project->id), [
        'name' => 'Dev',
        'type' => 'production',
    ])->assertSessionHasErrors('name');

    expect(Environment::query()->where('project_id', $project->id)->count())->toBe(2);
});

it('refuses a scoped member trying to add an environment to a project', function (): void {
    ['organization' => $account, 'subjectId' => $memberSubjectId, 'project' => $project] = provisionAccount();
    $staging = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');
    [$dev, $devSubjectId] = memberWithRole($account->id, MembershipRole::Developer, 'dev@acme.example');
    // Restrict the Developer to staging only — they can VIEW the project but must not
    // manage it (the env-add form is hidden; the server must refuse a direct call too).
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->setEnvironmentAccess($account->id, $devSubjectId, all: false, environmentIds: [$staging->id]));
    signInAsMember($devSubjectId);

    $this->post(route('projects.environments.store', $project->id), [
        'name' => 'Sneaky',
        'type' => 'production',
    ])->assertForbidden();

    expect(Environment::query()->where('project_id', $project->id)->count())->toBe(2);
});

it('suspends and reactivates a project', function (): void {
    ['member' => $member, 'subjectId' => $memberSubjectId, 'project' => $project] = provisionAccount();
    signInAsMember($memberSubjectId);

    $this->post(route('projects.suspend', $project->id))->assertRedirect();
    expect(Project::query()->whereKey($project->id)->value('status')?->value)->toBe('suspended');

    $this->post(route('projects.reactivate', $project->id))->assertRedirect();
    expect(Project::query()->whereKey($project->id)->value('status')?->value)->toBe('active');
});

it('lets a member create a second project and drills into it empty', function (): void {
    ['member' => $member, 'subjectId' => $memberSubjectId] = provisionAccount();
    signInAsMember($memberSubjectId);

    $this->post(route('projects.store'), ['name' => 'Product Two'])
        ->assertSessionHasNoErrors();

    $project = Project::query()->where('name', 'Product Two')->firstOrFail();

    // A brand-new project starts with no environments — the member adds them there.
    signInAsMember($memberSubjectId);
    $this->get(route('projects.show', $project->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/projects/show')
            ->where('project.name', 'Product Two')
            ->where('environments', []));
});

/**
 * The launchpad lists environments UNDER their project, so opening one — the thing
 * people actually come here for — is a single click rather than a drill-down through
 * a project page first.
 */
it('lists every environment grouped under its project, each with an Open link', function (): void {
    ['member' => $member, 'subjectId' => $memberSubjectId, 'project' => $project] = provisionAccount();
    $staging = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');

    signInAsMember($memberSubjectId);
    $this->get(route('projects'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('projects.0.name', 'Acme')
            // Both environments are on the launchpad itself...
            ->where('projects.0.environments', fn (Collection $environments): bool => $environments
                ->pluck('name')
                ->all() === ['Production', 'Staging'])
            // ...each with its own open link, plus the project's settings entry point.
            ->where('projects.0.environments.1.openHref', route('environment.open', $staging->id))
            ->where('projects.0.settingsHref', route('projects.show', $project->id)));
});

it('creates an environment inline from the launchpad', function (): void {
    ['member' => $member, 'subjectId' => $memberSubjectId, 'project' => $project] = provisionAccount();
    signInAsMember($memberSubjectId);

    $this->post(route('projects.environments.store', $project->id), [
        'name' => 'Staging',
        'type' => 'sandbox',
    ])->assertSessionHasNoErrors();

    expect(Environment::query()->where('project_id', $project->id)->where('name', 'Staging')->exists())->toBeTrue();
});

// The inline form is a convenience, never a second authorization path.
it('refuses an inline environment create for a project on another account', function (): void {
    ['member' => $member, 'subjectId' => $memberSubjectId] = provisionAccount();
    $other = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Rival',
        ownerEmail: 'owner@rival.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    signInAsMember($memberSubjectId);

    $this->post(route('projects.environments.store', $other->project->id), [
        'name' => 'Sneaky',
        'type' => 'production',
    ])->assertNotFound();

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
    ['member' => $member, 'subjectId' => $memberSubjectId] = provisionAccount();
    signInAsMember($memberSubjectId);

    // Seven since Activity was retired — see the redirect in routes/web.php.
    expect(identityPlatformPages())->toHaveCount(7);
});

it('gives a tenant of somebody else\'s IdP no area at all', function (): void {
    // A subject of a TENANT environment — the shape `acme.cboxid.com` serves. They
    // administer their own organization and own no identity providers, so there is
    // nothing here for them: the area holds no pages and the rail drops it, by the same
    // rule that already drops an area a module left empty.
    [$subjectId, $organization] = actingAsRole(MembershipRole::Owner);

    expect($subjectId)->not->toBe('')
        ->and($organization->slug)->not->toBe('')
        ->and(app(ConsoleScope::class)->membershipRole())->toBeNull()
        ->and(identityPlatformPages())->toBe([]);

    // …and at the page, not only in the rail — a rail is not an authorization check. They
    // are an OWNER of their own organization, so this is the area refusing them rather
    // than the console.
    test()->get(route('billing'))->assertRedirect(route('projects'));
})->group('security');

it('takes the area away from somebody who belongs to no organization', function (): void {
    ['member' => $member, 'subjectId' => $memberSubjectId, 'organization' => $account] = provisionAccount();

    // THE SAME PROPERTY, REACHED THE ONLY WAY IT STILL CAN BE. This used to null out
    // `accounts.organization_id` — an account that owned no organization, the state every
    // row created before that column existed was in. There is no account row and no such
    // column: a person's organization IS their membership, so "owns no organization" is
    // now "holds no membership".
    //
    // A subject who was never put on a roster, rather than this owner with their membership
    // taken away: an organization must keep at least one owner, so removing this one is
    // refused — which is that rule doing its job.
    //
    // What is asserted has not moved: an ownership that cannot be produced must fail CLOSED.
    // An earlier version returned the role here on the grounds that there was nothing to
    // compare against, which put the area in the rail on every organization the person
    // could act on, tenant hosts included. "We do not know which organization this person
    // owns" is not "all of them".
    $stranger = app(PlatformRoot::class)->run(
        fn () => app(Subjects::class)->create('nobody@acme.example', 'Nobody', 'a-strong-unbreached-passphrase'),
    );

    signInAsMember($stranger->id);

    // NULL, not the role.
    expect(app(ConsoleScope::class)->membershipRole())->toBeNull()
        ->and(identityPlatformPages())->toBe([]);

    // And at the page too, because a rail is not an authorization check.
    test()->get(route('billing'))->assertRedirect(route('projects'));
})->group('security');

it('takes the area away when the acting organization is not the person\'s own', function (): void {
    ['member' => $member, 'subjectId' => $memberSubjectId, 'organization' => $account] = provisionAccount();

    // The baseline: acting on the organization they belong to gives the area. This used to
    // assert `accounts.organization_id` was set — the homing that made an account point at
    // an organization. The account IS the organization, so there is nothing to home.
    signInAsMember($memberSubjectId);
    expect(app(ConsoleScope::class)->membershipRole())->toBe(MembershipRole::Owner);

    // Now act on a DIFFERENT organization the same person belongs to. Their account role
    // has not changed and their projects have not moved; what changed is who the console
    // says they are acting for, and offering them their own billing under another
    // tenant's name in the chrome is the drift this predicate exists to stop.
    $other = app(Organizations::class)->create(new NewOrganization('Somebody Else', 'somebody-else'));
    app(Memberships::class)->add($other->id, $memberSubjectId, MembershipRole::Member);

    // In the ROOT's scope: a member's subject and its session rows live there, and under
    // the suite's ambient environment the deny-by-default scope finds neither.
    $root = app(PlatformRoot::class);
    $subject = $root->run(fn () => app(Subjects::class)->find($memberSubjectId));
    $session = $root->run(fn () => app(SessionManager::class)->active((string) session(PlatformAuth::SESSION_KEY)));

    expect($subject)->not->toBeNull()->and($session)->not->toBeNull();

    session()->put(PlatformAuth::ORG_KEY, $other->id);
    app(CurrentUser::class)->set($subject, $session, app(Organizations::class)->find($other->id), MembershipRole::Member);

    expect(app(ConsoleScope::class)->membershipRole())->toBeNull()
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
it('shows exactly these pages to each role', function (MembershipRole $role, array $expected): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId, 'organization' => $account] = provisionAccount();

    // THROUGH THE CONTRACT, not by writing a row. A `forceFill` here passed for as long as
    // the member row was the authority, and stopped meaning anything the moment the console
    // started reading the membership. Going through the contract is what makes this table
    // describe the deployment rather than the fixture.
    //
    // A SECOND member for every role but Owner, because the first is the organization's
    // only owner and demoting the last owner is refused — the same reason ownership
    // transfer promotes before it demotes.
    if ($role === MembershipRole::Owner) {
        $subjectId = $ownerSubjectId;
    } else {
        [, $subjectId] = addMember($account->id, $role, 'matrix-'.$role->value.'@acme.example');
    }

    signInAsMember($subjectId);

    expect(identityPlatformPages())->toBe($expected, $role->value.' sees the wrong pages');
})->with([
    // Everything. Ownership itself is guarded per-action, not by hiding pages.
    'owner' => [MembershipRole::Owner, [
        'projects', 'members', 'api-keys', 'environment-keys',
        'environment-domains', 'billing', 'organization-settings',
    ]],

    // An admin is an owner minus the ownership transfer, which is not a page.
    'admin' => [MembershipRole::Admin, [
        'projects', 'members', 'api-keys', 'environment-keys',
        'environment-domains', 'billing', 'organization-settings',
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
        'projects', 'members', 'billing',
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
it('reads what a member may do from their membership', function (): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId, 'organization' => $account] = provisionAccount();

    // This was 'follows the membership when the member row disagrees', and it asserted that
    // the console read the membership rather than the account plane's own role column while
    // both existed. There is one row, so a disagreement is not a state the product can
    // reach — what survives is that the membership is what every capability gate asks, and
    // that is still worth pinning.
    [$member, $memberSubjectId] = addMember($account->id, MembershipRole::Admin, 'disagreement@acme.example');

    signInAsMember($memberSubjectId);
    expect(app(ConsoleScope::class)->capabilities()?->canManageMembers())->toBeTrue();

    // Change the role BEHIND THE CONTRACT'S BACK — nothing in the product writes this way.
    // It isolates the question: the console must read what the membership says right now,
    // not something it cached or derived from somewhere else.
    app(PlatformRoot::class)->run(fn () => DB::table('memberships')
        ->where('organization_id', $account->id)
        ->where('user_id', $memberSubjectId)
        ->update(['role' => MembershipRole::Viewer->value]));

    nextRequest();
    signInAsMember($memberSubjectId);

    // A Viewer may READ the roster and may not manage it — so the capability follows the
    // row, and the settings page (a manage-members surface) drops out of the area.
    expect(freshMembership($member)?->role)->toBe(MembershipRole::Viewer)
        ->and(app(ConsoleScope::class)->capabilities()?->canManageMembers())->toBeFalse()
        ->and(identityPlatformPages())->not->toContain('organization-settings');
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
it('refuses to re-role a customer\'s member from the environment roster', function (): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId, 'organization' => $account] = provisionAccount();

    [$target, $targetSubjectId] = addMember($account->id, MembershipRole::Developer, 'dev@acme.example');

    signInAsMember($ownerSubjectId);

    // ASSERTED ON THE REFUSAL, and on the row. The fence names itself in the message, so
    // "refused" is distinguishable from "did nothing" — which several other things in this
    // write could also produce.
    test()->from(route('directory.members'))
        ->patch(route('directory.members.role', $targetSubjectId), ['role' => MembershipRole::Admin->value])
        ->assertSessionHasErrors('role');

    // ONE ROW, and it did not move. The older version asserted on TWO — a member row and a
    // membership — because the defect it was written for was that they could disagree.
    // There is one now, so what is left to assert is the boundary: a customer's roster is
    // administered from the management console, by somebody holding an organization
    // capability, and never from a page whose authority is "administers this environment".
    expect(freshMembership($target)?->role)->toBe(MembershipRole::Developer);
})->group('security');

it('refuses to remove a customer\'s member from the environment roster', function (): void {
    ['member' => $owner, 'subjectId' => $ownerSubjectId, 'organization' => $account] = provisionAccount();

    [$target, $targetSubjectId] = addMember($account->id, MembershipRole::Developer, 'gone@acme.example');

    signInAsMember($ownerSubjectId);

    // Asserted on the REFUSAL, not only on the surviving membership.
    //
    // The first version checked that the membership was still there — and it passed with
    // the fence deleted, because several other things in this write can also leave it
    // standing. A test that cannot tell "refused" from "did nothing" is not a test of a
    // refusal. The sentence names where the roster IS administered, and this fence is the
    // only thing that says it.
    test()->from(route('directory.members'))
        ->delete(route('directory.members.remove', $targetSubjectId))
        ->assertSessionHasErrors(['member' => 'This organization is a customer of this platform. Manage its members under Identity platform → Members.']);

    // …and the membership is still there, which is the outcome that matters: removing it
    // alone would take somebody off the roster of the customer they belong to, from a page
    // whose authority is "administers this environment" and which never asked whether they
    // may manage that customer's people.
    expect(freshMembership($target))->not->toBeNull('the member lost their place in their own organization');
})->group('security');
