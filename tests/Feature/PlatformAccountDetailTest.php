<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentLineage;
use App\Platform\Console\EnvironmentLineages;
use App\Platform\Navigation\ConsoleNavigation;
use App\Platform\OperatorEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\Enums\ProjectStatus;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * The operator plane's shape, not just its pages.
 *
 * The hierarchy on this platform is account → project → environment. The operator plane
 * flattened it and inverted it: the rail listed Environments, then Accounts, then
 * Organizations — the leaf, the root, and a tenant inside the leaf — and the accounts
 * page, which computed a project and environment count per account, contained no
 * `route()` call at all. So an operator was shown six planes named `production`,
 * `staging`, `acme`, `acme-staging`, `billing-portal` and `demo-co`, told that Acme has
 * three projects and three environments, and given no way to find out which three.
 *
 * Every case below is one half of that: the walk from a customer down to its planes, and
 * the lineage on every plane wherever one is rendered.
 */
beforeEach(function (): void {
    actAsOperator('estate-op@platform.test');

    platformRootEnvironment();
});

/**
 * Acme, as the local install actually has it: two projects, three environments, and one
 * of those environments under the SECOND project — which is the row a flat list cannot
 * explain.
 *
 * @return array{account: Account, first: Project, second: Project, production: Environment, staging: Environment, portal: Environment}
 */
function acmeEstate(): array
{
    $provisioner = app(TenantProvisioner::class);

    $result = $provisioner->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'estate-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    $first = app(Projects::class)->forOrganization($result->organization->id)->firstOrFail();
    $staging = $provisioner->addEnvironment($first, 'Staging', type: EnvironmentType::Sandbox);

    $second = $provisioner->addProject($result->account, 'Billing Portal');
    $portal = $provisioner->addEnvironment($second, 'Production');

    return [
        'organization' => $result->organization,
        'first' => $first,
        'second' => $second,
        'production' => $result->environment,
        'staging' => $staging,
        'portal' => $portal,
    ];
}

/** An environment nobody owns — no project, therefore no account. The local `staging`. */
function anUnattachedEnvironment(): Environment
{
    return Environment::query()->create([
        'name' => 'Staging',
        'slug' => 'unattached-staging',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'settings' => [],
    ]);
}

it('puts the account first in the platform rail, above the environments inside it', function (): void {
    $platform = collect(app(ConsoleNavigation::class)->operator()->areas)
        ->firstOrFail(fn ($area): bool => $area->label === 'Platform');

    // Root → leaf. The list used to read Environments, Accounts, Organizations.
    expect(collect($platform->pages)->pluck('label')->all())
        ->toBe(['Accounts', 'Environments', 'Organizations']);
});

it('walks an operator from an account down to every environment its projects own', function (): void {
    $estate = acmeEstate();

    $html = (string) $this->get(route('platform.customers.show', $estate['account']->id))
        ->assertSuccessful()->getContent();

    // The heading is the customer, and both projects are named under it — including the
    // second one, whose environment is the row a flat list renders as an unexplained
    // "Production".
    expect($html)->toContain('<h1 class="cbx-page-title">Acme</h1>')
        ->and($html)->toContain('Billing Portal')
        ->and($html)->toContain($estate['staging']->slug)
        ->and($html)->toContain($estate['portal']->slug)
        ->and($html)->toContain($estate['production']->slug)
        // The team is here too — an account with nobody on it is a provisioning failure
        // an operator should be able to see.
        ->and($html)->toContain('estate-owner@acme.example');
});

it('takes its eyebrow and its lit rail entry from the nav registry, without writing either', function (): void {
    $estate = acmeEstate();

    $html = (string) $this->get(route('platform.customers.show', $estate['account']->id))
        ->assertSuccessful()->getContent();

    // `platform.customers.show` is a CHILD of `platform.customers`, so NavPage::owns() finds
    // it and both the eyebrow and the rail resolve with no hand-written label. This is the
    // whole reason the route is not called `platform.account`.
    expect($html)->toContain('<p class="cbx-page-eyebrow">Platform</p>')
        ->and($html)->toContain(' · Platform · ')
        ->and($html)->not->toContain(' · Workspace · ');
});

it('makes every count on the accounts list a link to the account it counts', function (): void {
    $estate = acmeEstate();

    $html = (string) $this->get(route('platform.customers'))->assertSuccessful()->getContent();
    $href = route('platform.customers.show', $estate['account']->id);

    // The finding in one assertion: this page computed three counts per account and had
    // no route() call in it, so all three were dead ends.
    expect(substr_count($html, 'href="'.$href.'"'))->toBeGreaterThanOrEqual(4)
        ->and($html)->toContain('2 projects on Acme')
        ->and($html)->toContain('3 environments on Acme');
});

it('points the console at an environment from the account page and stays on the account', function (): void {
    $estate = acmeEstate();
    $url = route('platform.customers.show', $estate['account']->id);

    livewireUpdate($url, 'platform.customer', 'target', [$estate['portal']->id])
        ->assertSuccessful();

    expect(session()->get(OperatorEnvironment::SESSION_KEY))->toBe($estate['portal']->slug);
});

it('opens an environment straight onto the tenants inside it', function (): void {
    $estate = acmeEstate();
    $url = route('platform.customers.show', $estate['account']->id);

    // Two steps that were only ever available as two pages: switch on the flat list, then
    // find Organizations in the rail.
    livewireUpdate($url, 'platform.customer', 'open', [$estate['staging']->id])
        ->assertSuccessful()
        ->assertJsonPath('components.0.effects.redirect', route('platform.organizations'));

    expect(session()->get(OperatorEnvironment::SESSION_KEY))->toBe($estate['staging']->slug);
});

/**
 * The ownership predicate on the two targeting actions, exercised through the REAL
 * endpoint — `Volt::test()` never routes, so it cannot tell you whether a crafted update
 * request gets through.
 */
it('refuses to repoint the console at an environment this account does not own', function (): void {
    $estate = acmeEstate();
    $stranger = anUnattachedEnvironment();

    $url = route('platform.customers.show', $estate['account']->id);

    livewireUpdate($url, 'platform.customer', 'target', [$stranger->id])->assertNotFound();
    livewireUpdate($url, 'platform.customer', 'open', [$stranger->id])->assertNotFound();

    // Including the platform root, which is the one an operator would most regret
    // repointing at by accident from a customer's page.
    livewireUpdate($url, 'platform.customer', 'target', [platformRootEnvironment()->id])->assertNotFound();

    expect(session()->get(OperatorEnvironment::SESSION_KEY))->toBeNull();
})->group('security');

it('suspends and reactivates the account from its own page, reversibly', function (): void {
    $estate = acmeEstate();
    $url = route('platform.customers.show', $estate['account']->id);

    livewireUpdate($url, 'platform.customer', 'toggleStatus')->assertSuccessful();
    expect(Account::query()->whereKey($estate['account']->id)->value('status'))->toBe(OrganizationStatus::Suspended);

    livewireUpdate($url, 'platform.customer', 'toggleStatus')->assertSuccessful();
    expect(Account::query()->whereKey($estate['account']->id)->value('status'))->toBe(OrganizationStatus::Active);
});

/**
 * The refusal, run the way it can actually be attacked: a snapshot captured while the
 * operator had authority is exactly what a retained browser tab holds after it is gone,
 * and it reaches every public method on the component without a fresh page load.
 *
 * `AuthenticateOperator` is on the persistent-middleware list, so it re-runs here and
 * answers first — which is the point of that list and why this asserts a redirect rather
 * than the component's own 404. The component's own guard is asserted separately below,
 * where nothing else can be answering.
 */
it('refuses every action on a snapshot whose operator authority is gone', function (): void {
    $estate = acmeEstate();
    $url = route('platform.customers.show', $estate['account']->id);

    $snapshot = snapshotFor(
        (string) $this->get($url)->assertSuccessful()->getContent(),
        'platform.customer',
    );

    forgetSubjectSession();
    nextRequest();

    // `login`, not `workspace.login` — the suite's baseline is a single-host install, so
    // an operator's sign-in is the root one (see AuthenticateOperator::signInRoute()).
    $this->get($url)->assertRedirect(route('login'));

    replaySnapshot($url, $snapshot, 'toggleStatus')->assertRedirect(route('login'));
    replaySnapshot($url, $snapshot, 'target', [$estate['portal']->id])->assertRedirect(route('login'));
    replaySnapshot($url, $snapshot, 'open', [$estate['portal']->id])->assertRedirect(route('login'));

    expect(Account::query()->whereKey($estate['account']->id)->value('status'))->toBe(OrganizationStatus::Active)
        ->and(session()->get(OperatorEnvironment::SESSION_KEY))->toBeNull();
})->group('security');

/**
 * The component's OWN guard, isolated.
 *
 * `Volt::test()` bypasses routing entirely — no `AuthenticateOperator`, no persistent
 * middleware — so this is the one place where the `abort_unless` in boot() is the only
 * thing that can be answering. That is also why the check lives in boot() rather than
 * mount(): Livewire re-runs mount only on the initial GET.
 */
it('refuses an account owner who is not an operator, on the component itself', function (): void {
    $estate = acmeEstate();
    $url = route('platform.customers.show', $estate['account']->id);

    // Provisioned BEFORE the session is dropped: nextRequest() ends the request, and with
    // it the ambient environment a subject has to be written into.
    $outsider = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Other',
        ownerEmail: 'not-an-operator@other.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->member;

    forgetSubjectSession();
    nextRequest();
    signInAsMember($outsider);

    // Somebody else's account, and a 404 rather than a 403: a 403 would confirm to any
    // account holder that this deployment has a staff console at that address.
    $this->get($url)->assertNotFound();

    Volt::test('platform.customer', ['organization' => $estate['account']->id])->assertStatus(404);
})->group('security');

it('gives every environment its lineage on the flat list, and names the two that have none', function (): void {
    $estate = acmeEstate();
    anUnattachedEnvironment();

    $html = (string) $this->get(route('platform.environments'))->assertSuccessful()->getContent();

    // "Production" is a name half the customers on an install will have. Both of Acme's
    // productions are qualified, and the one under the second project names its project.
    expect($html)->toContain('Acme / Production')
        ->and($html)->toContain('Acme / Staging')
        ->and($html)->toContain('Billing Portal')
        // …and the two rows with no account are named for what they are, rather than
        // rendered as a blank cell that reads like a broken join.
        ->and($html)->toContain('Platform root')
        ->and($html)->toContain('Unattached')
        // The account name in the column is a link into the account, so the flat list is
        // a way INTO the hierarchy rather than a place it disappears.
        ->and($html)->toContain(route('platform.customers.show', $estate['account']->id));
});

it('names the owner in the target switcher, on every console page', function (): void {
    $estate = acmeEstate();
    targetEnvironment($estate['portal']->slug);
    nextRequest();

    // The chrome control that decides which estate every subsequent read comes from used
    // to say "Production" and nothing else.
    expect((string) $this->get(route('platform.customers'))->assertSuccessful()->getContent())
        ->toContain('Acme / Production');
});

it('answers lineage for the platform root and for an orphan without inventing an owner', function (): void {
    $estate = acmeEstate();
    $root = platformRootEnvironment();
    $orphan = anUnattachedEnvironment();

    $lineage = app(EnvironmentLineages::class)->for([$root, $orphan, $estate['portal']]);

    expect($lineage[$root->id]->isPlatformRoot)->toBeTrue()
        ->and($lineage[$root->id]->isUnattached())->toBeFalse()
        ->and($lineage[$root->id]->owner())->toBe('Platform root')
        ->and($lineage[$orphan->id]->isPlatformRoot)->toBeFalse()
        ->and($lineage[$orphan->id]->isUnattached())->toBeTrue()
        ->and($lineage[$orphan->id]->owner())->toBe('Unattached')
        ->and($lineage[$orphan->id]->qualify('Staging'))->toBe('Unattached / Staging')
        ->and($lineage[$estate['portal']->id]->owner())->toBe('Acme')
        ->and($lineage[$estate['portal']->id]->projectName)->toBe('Billing Portal')
        ->and($lineage[$estate['portal']->id]->qualify('Production'))->toBe('Acme / Production');
});

it('describes an environment with no owner rather than leaving the reader to guess', function (): void {
    $root = new EnvironmentLineage(environmentId: 'e1', isPlatformRoot: true);
    $orphan = new EnvironmentLineage(environmentId: 'e2');
    $owned = new EnvironmentLineage(environmentId: 'e3', accountId: 'a1', organizationName: 'Acme');

    expect($root->note())->not->toBeNull()
        ->and($orphan->note())->not->toBeNull()
        ->and($owned->note())->toBeNull()
        ->and($owned->belongsToOrganization())->toBeTrue();
});

/** @return int the number of queries the request issued */
function lineageQueryCount(string $url): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->get($url)->assertSuccessful();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/**
 * The regression this whole resolver exists to prevent. `$environment->organization->name` in
 * the table would be one query per row on the page whose job is to show every plane on
 * the install — and the same again in the switcher, which renders on EVERY console page.
 */
it('does not pay a query per environment to say who owns one', function (): void {
    acmeEstate();

    $small = lineageQueryCount(route('platform.environments'));

    // Fifteen more accounts, each with its own project and environment. Created straight
    // through the models rather than the provisioner: these rows only have to EXIST for
    // the lineage joins, and provisioning each would mint a signing key apiece.
    for ($i = 0; $i < 15; $i++) {
        $account = Account::query()->create([
            'name' => 'Bulk '.$i,
            'status' => OrganizationStatus::Active,
            'environment_limit' => 2,
        ]);

        $project = Project::query()->create([
            'account_id' => $account->id,
            'name' => 'Bulk project '.$i,
            'slug' => 'bulk-project-'.$i,
            'status' => ProjectStatus::Active,
            'environment_limit' => 2,
        ]);

        Environment::query()->create([
            'account_id' => $account->id,
            'project_id' => $project->id,
            'name' => 'Production',
            'slug' => 'bulk-'.$i.'-'.Str::lower((string) Str::ulid()),
            'type' => EnvironmentType::Production,
            'status' => EnvironmentStatus::Active,
            'settings' => [],
        ]);
    }

    nextRequest();
    $large = lineageQueryCount(route('platform.environments'));

    fwrite(STDERR, "\n  platform.environments: {$small} queries at 4 planes, {$large} at 19\n");

    // Fifteen times the rows must not cost fifteen times the queries. A relation walk
    // would have added ~30 here (one per row, in the table and again in the switcher).
    expect($large - $small)->toBeLessThan(
        6,
        "the environments list costs per-row queries: {$small} at 4 planes, {$large} at 19",
    );
});
