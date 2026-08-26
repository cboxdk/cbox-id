<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;

/**
 * The environment console administers many organizations; the organization console
 * administers one. Modelling that as a picker — beside the environment switcher already
 * there — is what lets one component serve both planes.
 *
 * The control is chrome rather than a page, so it has no screen of its own — it is a
 * search endpoint and two writes, and the shell prop that names what is currently chosen.
 * These assert the behaviour against that surface: what a request may choose, what it is
 * refused, and what the chrome tells the browser it is acting on.
 */
function anEnvironmentAdmin(): void
{
    // `/admin` exists only on a multi-tenant deployment, so an environment administrator
    // is a multi-tenant fact. {@see \App\Http\Middleware\RequireMultiTenant}.
    multiTenantDeployment();

    platformRootEnvironment();

    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'picker-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->owner->id, $provisioned->environment->id);
}

it('chooses an organization in this environment', function (): void {
    anEnvironmentAdmin();
    $orgId = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co'))->id;

    test()->from(route('environment.home'))
        ->post(route('environment.acting-organization.choose'), ['organization' => $orgId])
        ->assertSessionHasNoErrors();

    expect(app(ConsoleScope::class)->organizationId())->toBe($orgId);
});

it('refuses an organization outside this environment rather than ignoring it', function (): void {
    // A silent no-op would leave an administrator looking at one organization's name
    // while acting on another's data — worse than an error, because they would not know.
    anEnvironmentAdmin();

    test()->from(route('environment.home'))
        ->post(route('environment.acting-organization.choose'), ['organization' => '01JQZZZZZZZZZZZZZZZZZZZZZZ'])
        ->assertSessionHasErrors(['organization' => 'That organization is not in this environment.']);

    expect(app(ConsoleScope::class)->organizationId())->toBeNull();
})->group('security');

it('is not reachable without an environment-admin session', function (): void {
    // The chrome is not a lesser surface than a page: it READS every organization name in
    // the environment and WRITES which one the console acts on. All three endpoints are
    // asked, because the search is the one that leaks and the two writes are the ones that
    // act — a guard on the writes alone would still hand a stranger the tenant list.
    // ON A HOST THAT DOES RESOLVE, with no session — otherwise the whole `/admin` prefix
    // 404s for want of an environment and the refusal proves nothing about this endpoint.
    multiTenantDeployment();
    platformRootEnvironment();

    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'stranger-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));

    app(Organizations::class)->create(new NewOrganization('Secret Tenant', 'secret-tenant'));

    // The SEARCH is the one that leaks and the two writes are the ones that act; a guard on
    // the writes alone would still hand a stranger the tenant list.
    $search = test()->getJson(route('environment.acting-organization.search'));

    expect($search->status())->not->toBe(200);
    expect($search->getContent())->not->toContain('Secret Tenant');

    // Bounced back to the account host to open an environment they cannot administer, and
    // that handoff refuses — asserted as "not an answer" plus the state afterwards, because
    // the shape of the refusal is the middleware's business and the WRITE not happening is
    // this endpoint's.
    $choose = test()->post(route('environment.acting-organization.choose'), ['organization' => 'x']);
    $clear = test()->delete(route('environment.acting-organization.clear'));

    expect($choose->status())->toBe(302)
        ->and($choose->headers->get('location'))->not->toContain('/admin')
        ->and($clear->status())->toBe(302)
        ->and($clear->headers->get('location'))->not->toContain('/admin')
        ->and(app(ConsoleScope::class)->organizationId())->toBeNull();
})->group('security');

it('shows the picker in the console chrome', function (): void {
    anEnvironmentAdmin();
    $org = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co'));

    // THE CHROME CARRIES IT, on every page — asserted on the shell prop rather than on one
    // page's own props, because a picker each page had to remember to pass is one some page
    // would not.
    $shell = (array) $this->get(route('environment.home'))->assertOk()->inertiaProps('shell');

    expect($shell['actingOrganization'])->not->toBeNull()
        // Unselected is the ORDINARY state and means the whole environment. It is null
        // rather than a sentinel, because null is what every read in this console tests for.
        ->and($shell['actingOrganization']['id'])->toBeNull();

    // …and the names come from the SEARCH, which is the whole reason this is not a list:
    // the chrome must not enumerate every tenant in the environment on every page.
    $this->getJson(route('environment.acting-organization.search'))
        ->assertOk()
        ->assertJsonPath('results.0.name', 'Tenant Co')
        ->assertJsonPath('total', 1);

    // And once chosen, the chrome says so rather than still reading "All organizations".
    test()->from(route('environment.home'))
        ->post(route('environment.acting-organization.choose'), ['organization' => $org->id]);

    $shell = (array) $this->get(route('environment.home'))->assertOk()->inertiaProps('shell');

    expect($shell['actingOrganization']['id'])->toBe($org->id)
        ->and($shell['actingOrganization']['name'])->toBe('Tenant Co');
});

/**
 * THE SEARCH IS BOUNDED, whatever the environment does.
 *
 * The control this replaces rendered a `<form>` per organization into the chrome of every
 * console page. Fine for the seven an engineer has locally; for the customer with four
 * thousand the document went from 59 KB to 3.5 MB before anybody clicked anything.
 */
it('answers a page of organizations and says how many more there are', function (): void {
    anEnvironmentAdmin();

    foreach (range(1, ConsoleScope::SWITCHER_LIMIT + 5) as $i) {
        app(Organizations::class)->create(new NewOrganization("Tenant {$i}", "tenant-{$i}"));
    }

    $this->getJson(route('environment.acting-organization.search'))
        ->assertOk()
        ->assertJsonCount(ConsoleScope::SWITCHER_LIMIT, 'results')
        // The total is asked only once the page is full, and it is what tells somebody
        // there is more behind what they can see.
        ->assertJsonPath('total', ConsoleScope::SWITCHER_LIMIT + 5);

    // And typing narrows it, rather than the list being all there is.
    $this->getJson(route('environment.acting-organization.search', ['q' => 'Tenant 3']))
        ->assertOk()
        ->assertJsonPath('results.0.name', 'Tenant 3');
});

/**
 * THE WAY BACK. Choosing used to be a one-way door: every list, count and form in the
 * console stayed filtered to that organization for the rest of the session, and signing
 * out was the only route back to the environment-wide view an administrator arrives on.
 */
it('offers the whole environment as a choice of its own', function (): void {
    anEnvironmentAdmin();
    $org = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co'));

    test()->from(route('environment.home'))
        ->post(route('environment.acting-organization.choose'), ['organization' => $org->id]);

    expect(app(ConsoleScope::class)->organizationId())->toBe($org->id);

    test()->from(route('environment.home'))
        ->delete(route('environment.acting-organization.clear'))
        ->assertSessionHasNoErrors();

    expect(app(ConsoleScope::class)->organizationId())->toBeNull();
});
