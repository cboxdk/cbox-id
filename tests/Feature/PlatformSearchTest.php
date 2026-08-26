<?php

declare(strict_types=1);

use App\Platform\OperatorEnvironment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Models\PlatformOperator;

uses(InteractsWithTenancy::class);

/** Sign a fresh operator into the session — reads are pinned to the default plane. */
function searchOperatorSignIn(string $email = 'search-op@platform.test'): PlatformOperator
{
    return actAsOperator($email);
}

/** A real environment row so the plane can be resolved to a human label. */
function makePlane(string $name, string $slug): Environment
{
    return Environment::query()->create(['name' => $name, 'slug' => $slug, 'status' => 'active']);
}

it('finds organizations and users across every environment, each labelled with its plane', function (): void {
    $planeA = makePlane('Plane A', 'plane-a');
    $planeB = makePlane('Plane B', 'plane-b');

    // Seed each plane entirely inside its own environment scope.
    $this->runAsEnvironment($planeA, function (): void {
        app(Organizations::class)->create(new NewOrganization('Acme Alpha', 'acme-alpha'));
        app(Subjects::class)->create('alpha@acme.test', 'Alpha User', 'supersecret123');
    });
    $this->runAsEnvironment($planeB, function (): void {
        app(Organizations::class)->create(new NewOrganization('Acme Beta', 'acme-beta'));
        app(Subjects::class)->create('beta@acme.test', 'Beta User', 'supersecret123');
    });

    searchOperatorSignIn();

    // A single search reaches BOTH planes (proving cross-environment reach via the
    // EnvironmentContext::withoutScope escape inside the component's with()).
    $results = platformSearch('acme');

    $organizations = collect($results['organizations']);
    $users = collect($results['users']);

    expect($organizations->pluck('name'))->toContain('Acme Alpha')
        ->and($organizations->pluck('name'))->toContain('Acme Beta')
        ->and($users->pluck('email'))->toContain('alpha@acme.test')
        ->and($users->pluck('email'))->toContain('beta@acme.test')
        // Each result carries its OWN plane label — a cross-plane list where every row said
        // the same plane would be the same bug as one that reached only one plane.
        ->and($organizations->pluck('plane')->unique()->sort()->values()->all())->toBe(['Plane A', 'Plane B'])
        ->and($users->pluck('plane')->unique()->sort()->values()->all())->toBe(['Plane A', 'Plane B']);
});

it('shows a hint instead of querying for a short term', function (): void {
    searchOperatorSignIn();

    expect(platformSearch()['ready'])->toBeFalse()
        ->and(platformSearch('a')['ready'])->toBeFalse()
        ->and(platformSearch('ab')['ready'])->toBeTrue();
});

it('refuses a signed-in non-operator with a 404', function (): void {
    // 404 rather than 403: a refusal from a page only staff may see is itself the
    // disclosure. Asked of a real person who is signed in and simply does not run this
    // deployment — an anonymous request is a different refusal, and PlatformUsageTest holds
    // the pair.
    actingAsRole(MembershipRole::Owner);

    test()->get(route('platform.search'))->assertNotFound();
});

it('treats a literal underscore as text, not a LIKE wildcard', function (): void {
    $plane = makePlane('Plane One', 'plane-one');

    $this->runAsEnvironment($plane, function (): void {
        $orgs = app(Organizations::class);
        // The target contains a literal underscore; the trap would only surface if
        // the underscore acted as a single-character wildcard.
        $orgs->create(new NewOrganization('Underscore Target ab_cd', 'underscore-target'));
        $orgs->create(new NewOrganization('Wildcard Trap abXcd', 'wildcard-trap'));
    });

    searchOperatorSignIn();

    $names = collect(platformSearch('ab_cd')['organizations'])->pluck('name');

    expect($names)->toContain('Underscore Target ab_cd')
        ->and($names)->not->toContain('Wildcard Trap abXcd');
});

it('jumps to a result in another plane by first re-pointing the console at its environment', function (): void {
    searchOperatorSignIn();
    $planeB = makePlane('Plane B', 'plane-b');

    $orgId = $this->runAsEnvironment($planeB, fn (): string => app(Organizations::class)
        ->create(new NewOrganization('Beta Org', 'beta-org'))->id);

    // The jump switches the operator's target plane, then redirects to the detail.
    $this->get(route('platform.search.jump', $orgId))
        ->assertRedirect(route('platform.organization', $orgId))
        ->assertSessionHas(OperatorEnvironment::SESSION_KEY, 'plane-b');

    // With the console now pinned to plane B, the plane-scoped detail page resolves
    // (it would have 404'd from the previous plane) and shows the tenant.
    $this->get(route('platform.organization', $orgId))
        ->assertOk()
        ->assertSee('Beta Org');
});

it('jumps from a user result to that user\'s organization in its plane', function (): void {
    searchOperatorSignIn();
    $planeB = makePlane('Plane B', 'plane-b');

    $orgId = $this->runAsEnvironment($planeB, function (): string {
        $org = app(Organizations::class)->create(new NewOrganization('Gamma Org', 'gamma-org'));
        $user = app(Subjects::class)->create('gamma@acme.test', 'Gamma User', 'supersecret123');
        app(Memberships::class)->add($org->id, $user->id, MembershipRole::Owner);

        return $org->id;
    });

    // The user's result exposes its org, and the row carries the JUMP for it — a user is
    // not a page, so the row opens the organization they belong to, in that org's own plane.
    $user = collect(platformSearch('gamma@acme.test')['users'])->firstWhere('email', 'gamma@acme.test');

    expect($user)->not->toBeNull()
        ->and(collect($user['organizations'])->pluck('name')->all())->toBe(['Gamma Org'])
        ->and($user['href'])->toBe(route('platform.search.jump', $orgId));

    $this->get(route('platform.search.jump', $orgId))
        ->assertRedirect(route('platform.organization', $orgId))
        ->assertSessionHas(OperatorEnvironment::SESSION_KEY, 'plane-b');
});

it('404s a jump to an organization that does not exist in any plane', function (): void {
    searchOperatorSignIn();

    $this->get(route('platform.search.jump', 'org_does_not_exist'))
        ->assertNotFound();
});
