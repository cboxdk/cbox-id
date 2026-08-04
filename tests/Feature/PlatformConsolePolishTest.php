<?php

declare(strict_types=1);

use App\Platform\Navigation\ConsoleNavigation;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
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

    signInAsMember(app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'polish-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->member);
}

it('offers an account-less operator no area that needs an account', function (): void {
    // Signed in: the platform areas are asked of ConsoleScope, so without a session the
    // rail below would be empty for a reason that has nothing to do with this test.
    polishOperator();

    // Null role IS "no membership" — see ConsoleNavigation::workspace().
    $labels = collect(app(ConsoleNavigation::class)->workspace(null)->areas)
        ->pluck('label')->all();

    // Overview › Projects explained what a project is and gated its only CTA off.
    // Personal › Profile rendered an empty form bound to nobody.
    expect($labels)->not->toContain('Overview')
        ->and($labels)->not->toContain('Personal')
        ->and($labels)->toContain('Platform');

    // …and a member still gets both.
    $memberLabels = collect(app(ConsoleNavigation::class)->workspace(AccountRole::Owner)->areas)
        ->pluck('label')->all();

    expect($memberLabels)->toContain('Overview')->and($memberLabels)->toContain('Personal');
});

it('lands an account-less operator on the platform rather than on an empty Projects page', function (): void {
    polishOperator();

    // Every door into the console redirects to workspace.home, so this is the landing
    // screen for the primary operator persona.
    $this->get(route('workspace.home'))->assertRedirect(route('platform.environments'));
});

it('sends an account-less operator to their OWN security page, not to a sign-in screen', function (): void {
    polishOperator();

    // The walked bug: Personal › Profile → Enable → /workspace/sudo → correct password →
    // /workspace/login, signed-out screen, no message, session still perfectly valid.
    $this->get(route('workspace.security'))
        ->assertRedirect(route('platform.security'))
        ->assertSessionMissing('errors');
});

it('names the plane in the title of every platform page', function (): void {
    polishOperator();

    // `Environments · Workspace · Cbox ID` named the customer plane on a page that
    // administers the whole install.
    foreach (['platform.environments', 'platform.usage', 'platform.operators'] as $route) {
        $html = (string) $this->get(route($route))->assertOk()->getContent();

        expect($html)->toContain(' · Platform · ')
            ->and($html)->not->toContain(' · Workspace · ');
    }

    // The account plane still says Workspace.
    polishOwner();
    expect((string) $this->get(route('workspace.home'))->assertOk()->getContent())
        ->toContain(' · Workspace · ');
});

it('gives every platform page the eyebrow its rail area actually uses', function (): void {
    polishOperator();

    // All eight hand-wrote "Platform", so Usage and Search claimed an area the rail did
    // not highlight, and so did Operators and Security.
    $expected = [
        'platform.environments' => 'Platform',
        'platform.accounts' => 'Platform',
        'platform.organizations' => 'Platform',
        'platform.usage' => 'Insights',
        'platform.search' => 'Insights',
        'platform.operators' => 'Administration',
        'platform.security' => 'Administration',
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
    foreach (['platform.organizations', 'platform.operators', 'platform.accounts'] as $route) {
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

    $html = (string) $this->get(route('workspace.home'))->assertOk()->getContent();

    expect($html)->not->toContain(route('platform.environments'))
        ->and($html)->not->toContain('Platform operator');

    // And the pages themselves refuse, not merely hide.
    $this->get(route('platform.environments'))->assertNotFound();
});

it('renders the platform lists as tables, so a column header cannot drift off its data', function (): void {
    polishOperator();

    // Header and body were two independent grids over the same `fr` tracks: measured on
    // /platform/accounts, the data under "Created" sat 121px left of its own heading,
    // because the header's last cell was an empty span and the body's a button cluster.
    foreach (['platform.environments', 'platform.accounts', 'platform.organizations'] as $route) {
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
