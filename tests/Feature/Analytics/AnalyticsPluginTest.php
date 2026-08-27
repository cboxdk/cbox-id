<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\Console\DashboardCards;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Analytics\Contracts\ReportSink;
use Cbox\Id\Analytics\Testing\FakeReportSink;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('leaves the analytics feature inactive with the inert null sink and disabled config', function (): void {
    expect(Console::featureActive('analytics'))->toBeFalse();
});

it('activates the analytics feature once a real sink is bound', function (): void {
    app()->instance(ReportSink::class, new FakeReportSink);

    expect(Console::featureActive('analytics'))->toBeTrue();
});

it('activates the analytics feature when explicitly enabled without a sink', function (): void {
    config()->set('id-analytics.enabled', true);

    expect(Console::featureActive('analytics'))->toBeTrue();
});

it('adds its page to the host Overview area, labelled the way the page is titled', function (): void {
    // Not its own area: Overview › Usage already answers "what is happening here", and
    // a second rail entry labelled "Overview" answered it again from somewhere else.
    expect(collect(Console::nav()->areas())->firstWhere('key', 'analytics'))->toBeNull();

    $area = collect(Console::nav()->areas())->firstWhere('key', 'overview');
    $page = collect($area->pages())->firstWhere('route', 'sign-in-activity');

    expect($page)->not->toBeNull()
        ->and($page->feature)->toBe('analytics')
        ->and($page->label)->toBe('Sign-in activity');
});

/**
 * The route name is `sign-in-activity`, not `analytics.overview`, and the name is the
 * point rather than an incidental rename.
 *
 * The environment console already serves `environment.analytics` — the environment's
 * usage counters — and a nav entry claims its own sub-routes by prefix. Named
 * `analytics.overview`, this page's environment-plane route would have been
 * `environment.analytics.overview`, which that entry owns: two highlighted items in one
 * sub-nav, which is the bug ConsoleNavigationTest pins for `environment.audit` and
 * `environment.audit-streams`. Pinned here so a later tidy-up that "restores" the
 * module-namespaced name has to argue with this first.
 */
it('names its route outside the environment console\'s analytics namespace', function (): void {
    expect(Route::has('sign-in-activity'))->toBeTrue()
        ->and(Route::has('environment.sign-in-activity'))->toBeTrue()
        // The page it must not hide under, so this test fails if that one is renamed
        // into the way rather than only if this one is renamed under it.
        ->and(Route::has('environment.analytics'))->toBeTrue()
        ->and(app(ConsoleScope::class)->routeName('sign-in-activity'))
        ->not->toStartWith('environment.analytics.');
});

it('renders a dashboard analytics card', function (): void {
    // AS AN ADMINISTRATOR OF AN ORGANIZATION, which this test used not to be. Every card
    // now narrows to the acting organization and renders NOTHING when there is none —
    // they used to read the whole environment, so they rendered for a caller with no
    // console scope at all, and that is exactly what this assertion was measuring.
    actingAsRole(MembershipRole::Owner);

    // THE CARD AS DATA, not as a rendered string. A module says what its card IS and the
    // console draws it, so the assertion is about the label and the number rather than
    // about markup a copy edit would move.
    $card = collect(app(DashboardCards::class)->resolve())->firstWhere('key', 'analytics.logins');

    expect($card)->not->toBeNull()
        ->and($card->label)->toBe('Logins (24h)');
});
