<?php

declare(strict_types=1);

use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

/**
 * Asked of the PROPS: the rows the server sent are the set the search narrowed, and a name
 * that merely fails to appear in the document could be a row that scrolled off a page.
 */
function customerNames(string $term = ''): Collection
{
    return collect(platformCustomers($term === '' ? [] : ['q' => $term])['customers'])->pluck('name');
}

it('narrows the customer list to what was searched for', function (): void {
    seedCustomers(3);

    expect(customerNames())->toContain('Northwind Traders')
        ->and(customerNames())->toContain('Customer 1')
        ->and(customerNames('Northwind'))->toContain('Northwind Traders')
        // The narrowing is the property. Without it the search box is decoration.
        ->and(customerNames('Northwind'))->not->toContain('Customer 1');
});

it('matches on the slug as well as the name', function (): void {
    seedCustomers(1);

    expect(customerNames('northwind-traders'))->toContain('Northwind Traders');
});

it('tells an operator their search missed, rather than that they have no customers', function (): void {
    seedCustomers(2);

    // Two empty states that mean opposite things: "nothing here yet" reads as reassurance
    // on a new install and as "your customers are gone" after a search that missed. The
    // page tells them apart by the term it was given, so that is what has to arrive.
    $props = platformCustomers(['q' => 'nothing-matches-this']);

    expect($props['customers'])->toBe([])
        ->and($props['search'])->toBe('nothing-matches-this');
});

it('bounds the page rather than rendering every customer on the install', function (): void {
    seedCustomers(30);

    // Bounded at the page size, not at the size of the estate. Before this, rendering
    // twenty-five rows also ran three aggregate queries across EVERY organization on the
    // install; the counts are now scoped to the page's ids.
    expect(platformCustomers()['customers'])->toHaveCount(25);
})->group('performance');

/**
 * REAL ENVIRONMENTS, and both halves asserted.
 *
 * This used to seed ORGANIZATIONS and search the ENVIRONMENT list, which held exactly one
 * row — the platform root. The page rendered its empty state, and the single occurrence of
 * "northwind" in the document was the term echoed back inside `No environments match
 * "northwind"`. The test asserted its own search box. It would have passed against a search
 * that matched everything, nothing, or the wrong column.
 *
 * Asked of the PROPS rather than of the rendered document: the rows the server sent are
 * the set the search narrowed, and a name that merely fails to appear in the HTML could
 * be a row that scrolled off a page or a term echoed back by an empty state.
 */
function environmentNames(string $term = ''): Collection
{
    return collect(platformEnvironments($term === '' ? [] : ['q' => $term])['environments'])
        ->pluck('name');
}

it('narrows the environment list, matching on slug and domain too', function (): void {
    seedEnvironments();

    // Present before the search, or "it disappeared" proves nothing.
    expect(environmentNames())->toContain('Northwind Production')
        ->and(environmentNames())->toContain('Contoso Staging');

    expect(environmentNames('northwind'))->toContain('Northwind Production')
        ->and(environmentNames('northwind'))->not->toContain('Contoso Staging');
});

it('matches an environment on its slug', function (): void {
    seedEnvironments();

    expect(environmentNames('contoso-staging'))->toContain('Contoso Staging')
        ->and(environmentNames('contoso-staging'))->not->toContain('Northwind Production');
});

it('matches an environment on a custom domain', function (): void {
    seedEnvironments();

    expect(environmentNames('id.northwind.example'))->toContain('Northwind Production')
        ->and(environmentNames('id.northwind.example'))->not->toContain('Contoso Staging');
});

it('says so plainly when nothing matches', function (): void {
    seedEnvironments();

    // The page draws its two empty states apart from this pair — an empty list AND a term
    // it echoes back — so "no environments match" cannot be rendered where the install
    // genuinely has none, and vice versa.
    $props = platformEnvironments(['q' => 'nothing-by-that-name']);

    expect($props['environments'])->toBe([])
        ->and($props['search'])->toBe('nothing-by-that-name');
});

/**
 * A LITERAL UNDERSCORE IS NOT A WILDCARD.
 *
 * `LIKE` reads `_` as "any one character", so a term containing one used to match rows it
 * has nothing to do with — the same defect the cross-plane search carries a test for.
 */
it('does not treat an underscore in an environment search as a wildcard', function (): void {
    seedEnvironments();

    expect(environmentNames('c_ntoso'))->not->toContain('Contoso Staging');
})->group('security');

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

    $rows = collect(platformOrganizations(['q' => 'Zenith'])['organizations']);

    expect($rows->pluck('name'))->toContain($childName)
        ->and($rows->pluck('name'))->not->toContain($parentName)
        // …and it renders as a ROOT rather than under a parent that is not on screen,
        // which is the honest thing to show for a filtered view of a tree.
        ->and($rows->firstWhere('name', $childName)['depth'])->toBe(0);
});

it('narrows the operator roster', function (): void {
    app(PlatformOperators::class)
        ->create('ada@platform.test', 'a-strong-unbreached-passphrase', 'Ada Lovelace');

    $names = fn (array $query = []): Collection => collect(
        (array) test()->get(route('platform.operators', $query))->assertOk()->inertiaProps('operators')
    )->pluck('name');

    expect($names())->toContain('Ada Lovelace')
        ->and($names(['q' => 'nobody-by-that-name']))->not->toContain('Ada Lovelace');
});
