<?php

declare(strict_types=1);

use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * The operator plane is the ONE plane that grows without bound — every customer and every
 * environment on the deployment, not one tenant's worth — and it was the only plane with
 * neither search nor paging. Thirteen tenant-facing console pages already had both.
 *
 * These assert the behaviour, not the markup: that a search NARROWS the set and that a
 * page BOUNDS it. A test that only checked for an `<input>` would pass on a search box
 * wired to nothing.
 */
beforeEach(function (): void {
    actAsOperator('list-op@platform.test');
    platformRootEnvironment();
});

function seedCustomers(int $count): void
{
    app(PlatformRoot::class)->run(function () use ($count): void {
        $organizations = app(Organizations::class);

        foreach (range(1, $count) as $i) {
            $organizations->create(new NewOrganization('Customer '.$i, 'customer-'.$i.'-'.Str::lower(Str::random(4))));
        }

        $organizations->create(new NewOrganization('Northwind Traders', 'northwind-traders'));
    });
}

/**
 * Two environments that differ in name, slug AND domain, so a search can be shown to match
 * on each of the three columns the page claims to search.
 */
function seedEnvironments(): void
{
    app(PlatformRoot::class)->run(function (): void {
        Environment::query()->create([
            'name' => 'Northwind Production',
            'slug' => 'northwind-production',
            'domain' => 'id.northwind.example',
        ]);

        Environment::query()->create([
            'name' => 'Contoso Staging',
            'slug' => 'contoso-staging',
            'domain' => 'id.contoso.example',
        ]);
    });
}

it('narrows the customer list to what was searched for', function (): void {
    seedCustomers(3);

    Volt::test('platform.customers')
        ->assertSee('Northwind Traders')
        ->assertSee('Customer 1')
        ->set('search', 'Northwind')
        ->assertSee('Northwind Traders')
        // The narrowing is the property. Without it the search box is decoration.
        ->assertDontSee('Customer 1');
});

it('matches on the slug as well as the name', function (): void {
    seedCustomers(1);

    Volt::test('platform.customers')
        ->set('search', 'northwind-traders')
        ->assertSee('Northwind Traders');
});

it('tells an operator their search missed, rather than that they have no customers', function (): void {
    seedCustomers(2);

    // Two empty states that mean opposite things: "nothing here yet" reads as reassurance
    // on a new install and as "your customers are gone" after a search that missed.
    Volt::test('platform.customers')
        ->set('search', 'nothing-matches-this')
        ->assertSee('No customers match')
        ->assertDontSee('No customers yet');
});

it('bounds the page rather than rendering every customer on the install', function (): void {
    seedCustomers(30);

    $html = Volt::test('platform.customers')->html();

    // Count the actual rows rather than guessing which names land where: 31 seeded, 25 to
    // a page. Row links are the reliable marker — each row links to its customer page.
    preg_match_all('#/platform/customers/[0-9a-zA-Z]+#', $html, $m);
    $onPage = count(array_unique($m[0]));

    // Bounded at the page size, not at the size of the estate. Before this, rendering
    // twenty-five rows also ran three aggregate queries across EVERY organization on the
    // install; the counts are now scoped to the page's ids.
    expect($onPage)->toBe(25);
})->group('performance');

/**
 * REAL ENVIRONMENTS, and both halves asserted.
 *
 * This used to seed ORGANIZATIONS and search the ENVIRONMENT list, which held exactly one
 * row — the platform root. The page rendered its empty state, and the single occurrence of
 * "northwind" in the document was the term echoed back inside `No environments match
 * "northwind"`. The test asserted its own search box. It would have passed against a search
 * that matched everything, nothing, or the wrong column.
 */
it('narrows the environment list, matching on slug and domain too', function (): void {
    seedEnvironments();

    Volt::test('platform.environments')
        // Present before the search, or "it disappeared" proves nothing.
        ->assertSee('Northwind Production')
        ->assertSee('Contoso Staging')
        ->set('search', 'northwind')
        ->assertSee('Northwind Production')
        ->assertDontSee('Contoso Staging');
});

it('matches an environment on its slug', function (): void {
    seedEnvironments();

    Volt::test('platform.environments')
        ->set('search', 'contoso-staging')
        ->assertSee('Contoso Staging')
        ->assertDontSee('Northwind Production');
});

it('matches an environment on a custom domain', function (): void {
    seedEnvironments();

    Volt::test('platform.environments')
        ->set('search', 'id.northwind.example')
        ->assertSee('Northwind Production')
        ->assertDontSee('Contoso Staging');
});

it('says so plainly when nothing matches', function (): void {
    seedEnvironments();

    Volt::test('platform.environments')
        ->set('search', 'nothing-by-that-name')
        ->assertDontSee('Northwind Production')
        ->assertDontSee('Contoso Staging')
        ->assertSee('No environments match');
});

/**
 * A TREE, so search must not hide a match whose parent did not match.
 *
 * The rows are flattened depth-first from the roots, so a child whose parent is filtered
 * out would be grouped under a parent key the walk never visits — present in the query
 * result and absent from the page. A search that hides matches is worse than no search.
 */
it('keeps a matching child visible when its parent did not match', function (): void {
    [$childName, $parentName] = app(PlatformRoot::class)->run(function (): array {
        $organizations = app(Organizations::class);
        $parent = $organizations->create(new NewOrganization('Umbrella Holdings', 'umbrella-holdings'));
        $child = $organizations->create(new NewOrganization('Zenith Subsidiary', 'zenith-subsidiary'));

        Organization::query()->whereKey($child->id)
            ->update(['parent_id' => $parent->id]);

        return [$child->name, $parent->name];
    });

    Volt::test('platform.organizations')
        ->set('search', 'Zenith')
        ->assertSee($childName)
        ->assertDontSee($parentName);
});

it('narrows the operator roster', function (): void {
    app(PlatformOperators::class)
        ->create('ada@platform.test', 'a-strong-unbreached-passphrase', 'Ada Lovelace');

    Volt::test('platform.operators')
        ->assertSee('Ada Lovelace')
        ->set('search', 'nobody-by-that-name')
        ->assertDontSee('Ada Lovelace');
});
