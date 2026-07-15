<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Livewire\Volt\Volt;

uses(InteractsWithTenancy::class);

/** Sign a fresh operator into the session — reads are pinned to the default plane. */
function searchOperatorSignIn(string $email = 'search-op@platform.test'): PlatformOperator
{
    $op = app(PlatformOperators::class)->create($email, 'a-strong-operator-pass', 'Op');
    session([OperatorAuth::SESSION_KEY => $op->id]);

    return $op;
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
    Volt::test('operator.search')
        ->set('term', 'acme')
        ->assertSee('Acme Alpha')
        ->assertSee('Acme Beta')
        ->assertSee('alpha@acme.test')
        ->assertSee('beta@acme.test')
        // Each result carries its own plane label.
        ->assertSee('Plane A')
        ->assertSee('Plane B');
});

it('shows a hint instead of querying for a short term', function (): void {
    searchOperatorSignIn();

    Volt::test('operator.search')
        ->assertViewHas('ready', false)
        ->set('term', 'a')
        ->assertViewHas('ready', false)
        ->set('term', 'ab')
        ->assertViewHas('ready', true);
});

it('refuses a non-operator request with a 403', function (): void {
    // No operator session — boot()'s per-request auth re-check aborts every request.
    Volt::test('operator.search')->assertForbidden();
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

    Volt::test('operator.search')
        ->set('term', 'ab_cd')
        ->assertSee('Underscore Target')
        ->assertDontSee('Wildcard Trap');
});

it('jumps to a result in another plane by first re-pointing the console at its environment', function (): void {
    $op = searchOperatorSignIn();
    $planeB = makePlane('Plane B', 'plane-b');

    $orgId = $this->runAsEnvironment($planeB, fn (): string => app(Organizations::class)
        ->create(new NewOrganization('Beta Org', 'beta-org'))->id);

    // The jump switches the operator's target plane, then redirects to the detail.
    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->get(route('operator.search.jump', $orgId))
        ->assertRedirect(route('operator.organization', $orgId))
        ->assertSessionHas(OperatorAuth::ENV_KEY, 'plane-b');

    // With the console now pinned to plane B, the plane-scoped detail page resolves
    // (it would have 404'd from the previous plane) and shows the tenant.
    $this->get(route('operator.organization', $orgId))
        ->assertOk()
        ->assertSee('Beta Org');
});

it('jumps from a user result to that user\'s organization in its plane', function (): void {
    $op = searchOperatorSignIn();
    $planeB = makePlane('Plane B', 'plane-b');

    $orgId = $this->runAsEnvironment($planeB, function (): string {
        $org = app(Organizations::class)->create(new NewOrganization('Gamma Org', 'gamma-org'));
        $user = app(Subjects::class)->create('gamma@acme.test', 'Gamma User', 'supersecret123');
        app(Memberships::class)->add($org->id, $user->id, 'owner');

        return $org->id;
    });

    // The user's result exposes its org, and the org's plane is resolved for the jump.
    Volt::test('operator.search')
        ->set('term', 'gamma@acme.test')
        ->assertSee('gamma@acme.test')
        ->assertSee('Gamma Org');

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->get(route('operator.search.jump', $orgId))
        ->assertRedirect(route('operator.organization', $orgId))
        ->assertSessionHas(OperatorAuth::ENV_KEY, 'plane-b');
});

it('404s a jump to an organization that does not exist in any plane', function (): void {
    $op = searchOperatorSignIn();

    $this->withSession([OperatorAuth::SESSION_KEY => $op->id])
        ->get(route('operator.search.jump', 'org_does_not_exist'))
        ->assertNotFound();
});
