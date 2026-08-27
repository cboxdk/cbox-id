<?php

declare(strict_types=1);

use App\Http\Props\Console\DashboardCardProps;
use App\Platform\Console\DashboardCards;
use App\Platform\PlatformAuth;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function pluginAdmin(): void
{
    $subject = app(Subjects::class)->create('plug@acme.test', 'Plug Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-plug'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
}

/** Whether the console's shell offers an area with this label. */
function shellOffersArea(string $label): bool
{
    $shell = (array) test()->get(route('dashboard'))->assertOk()->inertiaProps('shell');

    return collect($shell['areas'])->pluck('label')->contains($label);
}

it('shows a plugin-contributed nav area in the console with no host edit', function (): void {
    pluginAdmin();

    // Exactly what a plugin's service provider would do:
    Console::nav()->area('billing', 'Billing', 'layers', 90)->page('dashboard', 'Plan');

    // ASKED OF THE NAV, not of the document. The areas are shared props now, so they are
    // in the response either way — but `assertSee('Billing')` would also pass on a page
    // that merely mentioned the word, and it passed for a while over a serialised prop
    // nothing rendered.
    expect(shellOffersArea('Billing'))->toBeTrue();
});

it('hides a feature-gated page until its feature is active', function (): void {
    pluginAdmin();
    Console::nav()->area('extras', 'Extras', 'layers', 95)->page('dashboard', 'Reports', feature: 'reports');

    // 'reports' unregistered → deny-by-default → page hidden → empty area dropped.
    expect(shellOffersArea('Extras'))->toBeFalse();

    Console::features()->register('reports', true);

    expect(shellOffersArea('Extras'))->toBeTrue();
});

it('carries a plugin dashboard card to the page as data', function (): void {
    pluginAdmin();

    /*
     * A CARD IS DATA NOW, not a rendered string. This registered `'<div>MARKER</div>'` and
     * asserted the marker appeared in the document — which was the whole contract while the
     * dashboard was blade, and is exactly the contract that could not survive the page
     * becoming client-rendered. A module says what its card IS; the console draws it.
     */
    app(DashboardCards::class)->add(fn (): DashboardCardProps => new DashboardCardProps(
        key: 'plugin.marker',
        label: 'Plugin card',
        value: '42',
        caption: 'Contributed by a plugin with no host edit',
        icon: 'layers',
        tone: 'info',
        linkLabel: 'Open the plugin',
        linkHref: route('dashboard'),
    ));

    test()->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cards', fn (Collection $cards): bool => $cards
                ->firstWhere('key', 'plugin.marker')['value'] === '42'));
});

/**
 * @group security
 *
 * AND NOT TO A PLAIN MEMBER. A card is arbitrary module code reading whatever it decides to
 * read, for whoever the page renders for — so the row is inside the dashboard's admin
 * branch, and that placement is authorization rather than layout.
 */
it('never carries a dashboard card to a plain member', function (): void {
    $subject = app(Subjects::class)->create('member@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-member-cards'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    app(DashboardCards::class)->add(fn (): DashboardCardProps => new DashboardCardProps(
        key: 'plugin.secret',
        label: 'Something a member should not read',
        value: '99',
        caption: null,
        icon: 'layers',
        tone: 'info',
        linkLabel: null,
        linkHref: null,
    ));

    test()->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('cards', []));
})->group('security');

it('still renders the built-in nav areas from the registry', function (): void {
    pluginAdmin();

    expect(shellOffersArea('Overview'))->toBeTrue()
        ->and(shellOffersArea('Developers'))->toBeTrue();
});
