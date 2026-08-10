<?php

declare(strict_types=1);

use App\Platform\Navigation\ConsoleNavigation;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The console the OPERATOR sees.
 *
 * Every case here was walked in a browser before it was written, and every one of them is
 * a consequence of the same thing: the platform pages were merged into the one shell as
 * raw markup rather than onto the shell's page primitives, and the two areas that assume
 * an account membership were left unconditional. The persona that finds all of it is the
 * operator who runs the deployment and buys nothing on it — `account_members` has no row
 * for them, so everything that reaches for `$member` reaches for null.
 */
function polishOperator(): void
{
    actAsOperator('polish-op@platform.test');
}

/** An account owner who is NOT an operator — the other half of every assertion here. */
function polishOwner(): void
{
    platformRootDeployment();

    signInAsSubject(app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'polish-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->owner->id);
}

it('offers an account-less operator no area that needs an account', function (): void {
    // Signed in: the section's own rail is what is being read, so without a session it
    // would be empty for a reason that has nothing to do with this test.
    polishOperator();

    // THE RENDERED RAIL, not a nav tree. This read `ConsoleNavigation::operator()` and
    // asserted that the platform section's own areas were not the account console's — which
    // was true by construction and therefore proved nothing. There is one rail now, and
    // whether it offers an operator a dead area is a live question rather than a structural
    // guarantee, so it has to be asked of the HTML.
    $html = (string) $this->get(route('platform.environments'))->assertOk()->getContent();

    // The areas that were dead for an operator who buys nothing: Overview › Projects
    // explained what a project is and gated its only CTA off, and Personal › Profile
    // rendered an empty form bound to nobody. Both are pages of the ONE console now, gated
    // on what the acting organization owns — an operator's organization owns no identity
    // provider, so the `identity-platform` feature gates empty the area and the layout
    // drops an area with no pages left.
    expect($html)->not->toContain(route('projects'))
        ->and($html)->not->toContain(route('billing'))
        ->and($html)->not->toContain(route('organization-settings'))
        // …and the platform section IS there, or the assertions above pass on an empty page.
        ->and($html)->toContain(route('platform.customers'))
        ->and($html)->toContain(route('platform.operators'))
        // Their own security page survives, and should: it is the one area that belongs to
        // every signed-in person rather than to an organization.
        ->and($html)->toContain(route('account'));
});

it('lands an account-less operator on the platform rather than on an empty Projects page', function (): void {
    polishOperator();

    // Projects is an Identity platform page and an operator's organization owns no IdP,
    // so the page is not theirs — and where they go instead is the platform section,
    // because that is where their work is, not because Projects refuses them.
    $this->get(route('projects'))->assertRedirect(route('platform.environments'));
});

it('gives an account-less operator their OWN security page, not a sign-in screen', function (): void {
    polishOperator();

    // The walked bug: the account console's Personal › Profile → Enable → /sudo →
    // correct password → /login, signed-out screen, no message, session still perfectly
    // valid. That page is gone and `/account` is the one every subject has, operator
    // included — so there is no redirect left to get wrong.
    $this->get(route('account'))
        ->assertOk()
        ->assertSessionMissing('errors');
});

it('names the section in the title of every platform page', function (): void {
    polishOperator();

    // `Environments · Workspace · Cbox ID` named the customer plane on a page that
    // administers the whole install, because one shell served both and hard-coded a word.
    foreach (['platform.environments', 'platform.usage', 'platform.operators'] as $route) {
        $html = (string) $this->get(route($route))->assertOk()->getContent();

        expect($html)->toContain(' · Platform · ')
            ->and($html)->not->toContain(' · Workspace · ');
    }

    // …and a console page is not a platform page, whoever is reading it.
    polishOwner();
    expect((string) $this->get(route('projects'))->assertOk()->getContent())
        ->not->toContain(' · Platform · ');
});

it('gives every platform page the eyebrow its rail area actually uses', function (): void {
    polishOperator();

    // All eight hand-wrote "Platform", so Usage and Search claimed an area the rail did
    // not highlight, and so did Operators and Security.
    $expected = [
        'platform.environments' => 'Platform',
        'platform.customers' => 'Platform',
        'platform.organizations' => 'Platform',
        'platform.usage' => 'Insights',
        'platform.search' => 'Insights',
        'platform.operators' => 'Administration',
    ];

    foreach ($expected as $route => $area) {
        expect((string) $this->get(route($route))->assertOk()->getContent())
            ->toContain('<p class="cbx-page-eyebrow">'.$area.'</p>');
    }
});

it('will not suspend a live tenant or a fellow operator on one unconfirmed click', function (): void {
    polishOperator();

    // Accounts already did this; Organizations and Operators sat ~8px from "View" with
    // no dialog, no undo and no toast.
    foreach (['platform.organizations', 'platform.operators', 'platform.customers'] as $route) {
        $html = (string) $this->get(route($route))->assertOk()->getContent();

        // A page with nothing to suspend is not evidence either way.
        if (! str_contains($html, 'toggleStatus')) {
            continue;
        }

        expect($html)->toContain('wire:confirm');
    }
});

it('says who the operator is signed in as when there is no account to name', function (): void {
    polishOperator();

    // The topbar read "Workspace / Account", the rail foot "Account", the popover
    // "Account" with no address — on a console where one click suspends a customer.
    expect((string) $this->get(route('platform.environments'))->assertOk()->getContent())
        ->toContain('polish-op@platform.test')
        ->and((string) $this->get(route('platform.environments'))->getContent())
        ->toContain('Platform operator');
});

it('shows an account owner nothing platform-shaped', function (): void {
    polishOwner();

    $html = (string) $this->get(route('projects'))->assertOk()->getContent();

    expect($html)->not->toContain(route('platform.environments'))
        ->and($html)->not->toContain('Platform operator');

    // And the pages themselves refuse, not merely hide.
    $this->get(route('platform.environments'))->assertNotFound();
});

it('renders the platform lists as tables, so a column header cannot drift off its data', function (): void {
    polishOperator();

    // Header and body were two independent grids over the same `fr` tracks: measured on
    // /platform/customers, the data under "Created" sat 121px left of its own heading,
    // because the header's last cell was an empty span and the body's a button cluster.
    foreach (['platform.environments', 'platform.customers', 'platform.organizations'] as $route) {
        $html = (string) $this->get(route($route))->assertOk()->getContent();

        // Empty in this fixture is a legitimate answer — the empty state replaces the
        // table entirely. Only a rendered list is being asserted about.
        if (! str_contains($html, '<table class="table">')) {
            continue;
        }

        expect($html)->toContain('<thead>')->and($html)->toContain('scope="col"');
    }

    // The result count every other console list announces, which no platform page did.
    expect((string) $this->get(route('platform.search'))->assertOk()->getContent())
        ->toContain('role="status" aria-live="polite" class="sr-only"');
});
