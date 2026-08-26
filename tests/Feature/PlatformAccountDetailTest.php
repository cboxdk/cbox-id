<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentLineage;
use App\Platform\Console\EnvironmentLineages;
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

    $second = $provisioner->addProject($result->organization, 'Billing Portal');
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

it('puts the customer first in the platform rail, above the environments inside it', function (): void {
    // Read from the registry: the platform section is an area of the one console now, and
    // its ordering is the area's `order` numbers rather than argument order in a
    // constructor. Which means this test is checking something it could not check before —
    // that the numbers say what the old positional list said.
    $platform = collect(Console::nav()->areas())
        ->firstOrFail(fn ($area): bool => $area->key === 'platform');

    // Root → leaf. The list used to read Environments, Accounts, Organizations — and the
    // first entry is 'Customers' now, because a customer is not an account and the rail was
    // the last place still saying so while the URL beneath it already said /customers.
    expect(collect($platform->pages())->pluck('label')->all())
        ->toBe(['Customers', 'Environments', 'Organizations']);
});

it('walks an operator from an account down to every environment its projects own', function (): void {
    $estate = acmeEstate();

    $props = platformCustomer($estate['organization']->id);

    $projects = collect($props['projects']);
    $slugs = $projects->pluck('environments')->flatten(1)->pluck('slug');

    // The heading is the customer, and both projects are named under it — including the
    // second one, whose environment is the row a flat list renders as an unexplained
    // "Production".
    expect($props['customer']['name'])->toBe('Acme')
        ->and($projects->pluck('name'))->toContain('Billing Portal')
        ->and($slugs)->toContain($estate['staging']->slug)
        ->and($slugs)->toContain($estate['portal']->slug)
        ->and($slugs)->toContain($estate['production']->slug)
        // The team is here too — an account with nobody on it is a provisioning failure an
        // operator should be able to see.
        ->and(collect($props['members'])->pluck('email'))->toContain('estate-owner@acme.example');
});

it('takes its eyebrow and its lit rail entry from the nav registry, without writing either', function (): void {
    $estate = acmeEstate();

    $props = platformCustomer($estate['organization']->id);

    /*
     * `platform.customers.show` is a CHILD of `platform.customers`, so NavPage::owns() finds
     * it and both the eyebrow and the lit rail entry resolve with no hand-written label.
     * This is the whole reason the route is not called `platform.account`.
     *
     * Asserted as the PAIR — an active area whose key resolves to a label — rather than as
     * a rendered `<p>`: the eyebrow is drawn by `<PageHeader>` from `shell.activeArea` and
     * from nothing else, and an activeArea absent from `areas` renders no eyebrow at all,
     * which is the failure this exists to catch. That the element is drawn is held in the
     * browser suite, which is the only place that can see it.
     */
    $shell = (array) $props['shell'];
    $area = collect($shell['areas'])->firstWhere('key', $shell['activeArea']);

    expect($area)->not->toBeNull('the page named an area the rail does not have')
        ->and($area['label'])->toBe('Platform');

    // …and the section is in the tab title, which is the server's and not the bundle's.
    expect((string) test()->get(route('platform.customers.show', $estate['organization']->id))->getContent())
        ->toContain(' · Platform · ')
        ->not->toContain(' · Workspace · ');
});

it('makes every count on the accounts list a link to the account it counts', function (): void {
    $estate = acmeEstate();

    $row = collect(platformCustomers()['customers'])->firstWhere('id', $estate['organization']->id);

    // The finding, as the row: this page computed three counts per account and had no
    // route() call in it at all, so all three were dead ends. The destination is carried
    // once and the page hangs every count on it.
    expect($row['href'])->toBe(route('platform.customers.show', $estate['organization']->id))
        ->and($row['projects'])->toBe(2)
        ->and($row['environments'])->toBe(3);
});

it('points the console at an environment from the account page and stays on the account', function (): void {
    $estate = acmeEstate();

    targetCustomerEnvironment($estate['organization']->id, $estate['portal']->id)
        ->assertRedirect(route('platform.customers.show', $estate['organization']->id));

    expect(session()->get(OperatorEnvironment::SESSION_KEY))->toBe($estate['portal']->slug);
});

it('opens an environment straight onto the tenants inside it', function (): void {
    $estate = acmeEstate();

    // Two steps that were only ever available as two pages: switch on the flat list, then
    // find Organizations in the rail.
    openCustomerEnvironment($estate['organization']->id, $estate['staging']->id)
        ->assertRedirect(route('platform.organizations'));

    expect(session()->get(OperatorEnvironment::SESSION_KEY))->toBe($estate['staging']->slug);
});

/**
 * The ownership predicate on the two targeting actions, exercised through the REAL
 * endpoint. Both take an environment id straight off the request, and without the
 * predicate either one would repoint the console at any environment on the install.
 */
it('refuses to repoint the console at an environment this account does not own', function (): void {
    $estate = acmeEstate();
    $stranger = anUnattachedEnvironment();
    $organizationId = $estate['organization']->id;

    targetCustomerEnvironment($organizationId, $stranger->id)->assertNotFound();
    openCustomerEnvironment($organizationId, $stranger->id)->assertNotFound();

    // Including the platform root, which is the one an operator would most regret
    // repointing at by accident from a customer's page.
    targetCustomerEnvironment($organizationId, platformRootEnvironment()->id)->assertNotFound();

    expect(session()->get(OperatorEnvironment::SESSION_KEY))->toBeNull();
})->group('security');

it('suspends and reactivates the account from its own page, reversibly', function (): void {
    $estate = acmeEstate();

    toggleCustomer($estate['organization']->id)->assertSessionHasNoErrors();
    expect(Organization::query()->whereKey($estate['organization']->id)->value('status'))->toBe(OrganizationStatus::Suspended);

    // …and the page SAYS so, or the button is a database write nothing reflects.
    expect(platformCustomer($estate['organization']->id)['customer']['active'])->toBeFalse();

    toggleCustomer($estate['organization']->id)->assertSessionHasNoErrors();
    expect(Organization::query()->whereKey($estate['organization']->id)->value('status'))->toBe(OrganizationStatus::Active);
});

/**
 * THE REFUSAL, RUN THE WAY IT CAN ACTUALLY BE ATTACKED.
 *
 * This used to replay a Livewire snapshot captured while the operator had authority —
 * exactly what a retained browser tab holds after that authority is gone, and it reached
 * every public method on the component without a fresh page load. Under Inertia there is no
 * snapshot and no shared update endpoint: each action is its own route with its own stack,
 * so the equivalent attack is the retained tab POSTing to those routes, which is what this
 * does. Every one of them must refuse, and nothing may change.
 */
it('refuses every write on this page once the operator authority is gone', function (): void {
    $estate = acmeEstate();
    $organizationId = $estate['organization']->id;

    $this->get(route('platform.customers.show', $organizationId))->assertSuccessful();

    forgetSubjectSession();
    nextRequest();

    // `login`, not `workspace.login` — the suite's baseline is a single-host install, so an
    // operator's sign-in is the root one (see AuthenticateOperator::signInRoute()).
    $this->get(route('platform.customers.show', $organizationId))->assertRedirect(route('login'));

    toggleCustomer($organizationId)->assertRedirect(route('login'));
    targetCustomerEnvironment($organizationId, $estate['portal']->id)->assertRedirect(route('login'));
    openCustomerEnvironment($organizationId, $estate['portal']->id)->assertRedirect(route('login'));

    expect(Organization::query()->whereKey($organizationId)->value('status'))->toBe(OrganizationStatus::Active)
        ->and(session()->get(OperatorEnvironment::SESSION_KEY))->toBeNull();
})->group('security');

/**
 * And the same for a session that IS signed in and simply is not an operator's — a
 * different answer from the one above, and deliberately: no session at all is a step the
 * visitor can take, while a session that is not an operator's must 404 rather than confirm
 * that this deployment has a staff console at that address.
 */
it('refuses an account owner who is not an operator, on the reads and on the writes', function (): void {
    $estate = acmeEstate();
    $organizationId = $estate['organization']->id;

    // Provisioned BEFORE the session is dropped: nextRequest() ends the request, and with
    // it the ambient environment a subject has to be written into.
    $outsider = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Other',
        ownerEmail: 'not-an-operator@other.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->membership;

    forgetSubjectSession();
    nextRequest();
    signInAsMember($outsider->user_id);

    $this->get(route('platform.customers.show', $organizationId))->assertNotFound();

    // EVERY WRITE TOO. Under Livewire these shared one endpoint and the page had to re-ask
    // in boot(); each is now its own route, which is what makes asking them one by one the
    // honest test rather than a formality.
    toggleCustomer($organizationId)->assertNotFound();
    targetCustomerEnvironment($organizationId, $estate['portal']->id)->assertNotFound();
    openCustomerEnvironment($organizationId, $estate['portal']->id)->assertNotFound();

    expect(Organization::query()->whereKey($organizationId)->value('status'))->toBe(OrganizationStatus::Active);
})->group('security');

it('gives every environment its lineage on the flat list, and names the two that have none', function (): void {
    $estate = acmeEstate();
    anUnattachedEnvironment();

    $rows = collect(platformEnvironments()['environments']);

    // "Production" is a name half the customers on an install will have. Both of Acme's
    // productions are qualified, and the one under the second project names its project.
    expect($rows->pluck('qualifiedName'))->toContain('Acme / Production')
        ->and($rows->pluck('qualifiedName'))->toContain('Acme / Staging')
        // …and the second project is NAMED under the owner, which is the only thing that
        // tells two identically-named productions of the same customer apart.
        ->and($rows->pluck('lineage.projectName'))->toContain('Billing Portal')
        // …and the two rows with no account are named for what they are, rather than
        // handed to the page as a blank owner that reads like a broken join.
        ->and($rows->pluck('lineage.owner'))->toContain('Platform root')
        ->and($rows->pluck('lineage.owner'))->toContain('Unattached');

    // The account name in the column is a link into the account, so the flat list is a way
    // INTO the hierarchy rather than a place it disappears.
    expect($rows->pluck('lineage.organizationHref')->filter()->unique()->values()->all())
        ->toBe([route('platform.customers.show', $estate['organization']->id)]);
});

it('names the owner in the target switcher, on every console page', function (): void {
    $estate = acmeEstate();
    targetEnvironment($estate['portal']->slug);
    nextRequest();

    // The chrome control that decides which estate every subsequent read comes from used
    // to say "Production" and nothing else. Read off the shared shell prop, which is where
    // every console page on both planes gets the switcher from — so this is one assertion
    // about all of them rather than about the page it happens to be made on.
    $options = collect((array) $this->get(route('platform.customers'))->assertSuccessful()->inertiaProps('shell.environments'));

    expect($options->pluck('label'))->toContain('Acme / Production')
        // And the CURRENT one is the one just targeted, or the label above could belong to
        // any row in the menu.
        ->and($options->firstWhere('current', true)['label'])->toBe('Acme / Production');
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
    $owned = new EnvironmentLineage(environmentId: 'e3', organizationId: 'a1', organizationName: 'Acme');

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

    // Fifteen more customers, each with its own project and environment. Created straight
    // through the models rather than the provisioner: these rows only have to EXIST for the
    // lineage joins, and provisioning each would mint a signing key apiece.
    //
    // The plan allowance lives on the PROJECT, never on the organization — an organization
    // has no such column, because one customer can own several independently-billed
    // products. And an environment names its project, not its owner: ownership runs through
    // the project rather than through a column beside it.
    for ($i = 0; $i < 15; $i++) {
        $account = Organization::query()->create([
            'name' => 'Bulk '.$i,
            'slug' => 'bulk-'.$i,
            'status' => OrganizationStatus::Active,
        ]);

        $project = Project::query()->create([
            'organization_id' => $account->id,
            'name' => 'Bulk project '.$i,
            'slug' => 'bulk-project-'.$i,
            'status' => ProjectStatus::Active,
            'environment_limit' => 2,
        ]);

        Environment::query()->create([
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
