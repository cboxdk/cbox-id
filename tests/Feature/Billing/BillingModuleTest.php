<?php

declare(strict_types=1);

use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Billing is an OPTIONAL module, and these are the assertions that make that word mean
 * something.
 *
 * It was a hardcoded nav line in the host's ConsoleServiceProvider and a hardcoded
 * `Volt::route` in routes/web.php, behind a capability check. That made it invisible to a
 * member who may not read it and present in every deployment regardless — a self-hosted
 * install with no plans and no invoices still carried the surface, and "off" was not a
 * state the feature had. It is a module under `modules/` now, registering through the same
 * console-kit sockets the other six use.
 *
 * THE TWO GATES ARE DIFFERENT QUESTIONS, and the first version of this module collapsed
 * them into one flag. A red test caught it: gating the ROUTE on the capability turned the
 * page's own "not for you, here's Projects" redirect into a 404, because the outer gate
 * wins and the page's answer never runs. Deployment-level absence and person-level refusal
 * are separate, and both are pinned below.
 */
it('serves billing when the deployment bills', function (): void {
    ['subjectId' => $ownerSubjectId] = provisionAccount();

    signInAsMember($ownerSubjectId);

    $this->get(route('billing'))->assertOk();
});

it('has no billing route at all when the module is switched off', function (): void {
    config(['billing.enabled' => false]);

    ['subjectId' => $ownerSubjectId] = provisionAccount();

    signInAsMember($ownerSubjectId);

    // ABSENT, not forbidden — 404 for the OWNER, who is the one person on the account who
    // certainly may read billing. A 403 here would mean the surface still exists and the
    // deployment merely declines to show it, which is the state this extraction removed.
    $this->get('/billing')->assertNotFound();
});

it('drops the billing nav entry when the module is switched off', function (): void {
    config(['billing.enabled' => false]);

    ['subjectId' => $ownerSubjectId] = provisionAccount();

    signInAsMember($ownerSubjectId);

    // The rail must not offer a link to a route that no longer exists. Asserted on the
    // rendered console rather than on the registry: the page is declared either way, and
    // whether it is DRAWN is the question.
    $html = (string) $this->get(route('projects'))->assertOk()->getContent();

    expect($html)->not->toContain('>Billing<');
});

it('keeps billing out of a developer\'s rail while leaving the page reachable', function (): void {
    ['organization' => $organization] = provisionAccount();
    [, $devSubjectId] = memberWithRole($organization->id, MembershipRole::Developer, 'billing-dev@acme.example');

    signInAsMember($devSubjectId);

    // A Developer is a technical role with no claim on the money: no nav entry…
    expect(Console::featureActive('organization.billing'))->toBeFalse()
        // …but the deployment still bills, so the route exists and the page itself decides.
        // That distinction is the whole reason there are two features.
        ->and(Console::featureActive('billing'))->toBeTrue();

    signInAsMember($devSubjectId);
    $this->get(route('billing'))->assertRedirect(route('projects'));
});

it('registers billing without the host naming it', function (): void {
    // The point of the extraction: the host declares the area, the module fills a slot in
    // it. If this ever fails because the host started declaring the page again, the module
    // has become decoration.
    $host = file_get_contents(base_path('app/Providers/ConsoleServiceProvider.php'));
    $routes = file_get_contents(base_path('routes/web.php'));

    expect($host)->not->toContain("->page('billing'")
        ->and($routes)->not->toContain("Volt::route('/billing'")
        ->and(Route::has('billing'))->toBeTrue();
});
