<?php

declare(strict_types=1);

use App\Platform\Navigation\ConsoleNavigation;
use Cbox\Id\Platform\Contracts\PlatformOperators;
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

    /*
     * THE RAIL AS THE SERVER BUILT IT. This read `ConsoleNavigation::operator()` and
     * asserted that the platform section's own areas were not the account console's — true
     * by construction, and therefore proof of nothing. There is one rail now, and whether
     * it offers an operator a dead area is a live question.
     *
     * Asked of the shared shell prop rather than of the document: the rail is drawn from
     * `shell.areas` and from nothing else, so this is the set of doors the operator is
     * actually given — and a URL absent from the HTML could simply be a page the bundle
     * has not drawn yet.
     */
    $shell = (array) $this->get(route('platform.environments'))->assertOk()->inertiaProps('shell');

    $routes = collect($shell['areas'])->pluck('pages')->flatten(1)->pluck('route');

    // The areas that were dead for an operator who buys nothing: Overview › Projects
    // explained what a project is and gated its only CTA off, and Personal › Profile
    // rendered an empty form bound to nobody. Both are pages of the ONE console now, gated
    // on what the acting organization owns — an operator's organization owns no identity
    // provider, so the `identity-platform` feature gates empty the area and the layout
    // drops an area with no pages left.
    expect($routes)->not->toContain('projects')
        ->and($routes)->not->toContain('billing')
        ->and($routes)->not->toContain('organization-settings')
        // …and the platform section IS there, or the assertions above pass on an empty rail.
        ->and($routes)->toContain('platform.customers')
        ->and($routes)->toContain('platform.operators')
        // Their own security page survives, and should: it is the one area that belongs to
        // every signed-in person rather than to an organization.
        ->and($routes)->toContain('account');
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

    /*
     * ASKED AS THE PAIR the eyebrow is drawn from — an active area whose key resolves to a
     * label — rather than as a rendered `<p>`. `<PageHeader>` reads `shell.activeArea` and
     * nothing else, and an activeArea absent from `areas` renders no eyebrow at all, which
     * is the failure this exists to catch. That the element is drawn is held in
     * tests/Browser, which is the only place that can see it.
     */
    foreach ($expected as $route => $area) {
        $shell = (array) $this->get(route($route))->assertOk()->inertiaProps('shell');
        $active = collect($shell['areas'])->firstWhere('key', $shell['activeArea']);

        expect($active)->not->toBeNull($route.' named an area the rail does not have')
            ->and($active['label'])->toBe($area, $route.' claims an area the rail does not highlight');
    }
});

/**
 * SUSPENDING SOMEBODY IS NEVER ONE CLICK.
 *
 * This used to grep three blades for `wire:confirm`, and was vacuous from 1.0.0 until it
 * was fixed: the fixture created ONE operator and no customer at all, so all three pages
 * rendered their empty states or withheld the Suspend control, every route hit the
 * `continue`, and the assertion ran zero times. Deleting every `wire:confirm` from all
 * three blades left it green.
 *
 * The markup is gone and the confirmation now lives in a React dialog, which no
 * request-level test can see — so what is asked here is what the SERVER decides: that each
 * of these lists hands the page a suspend URL of its own for a row that is not the acting
 * operator, and that the URL is a POST rather than a link something can follow. The dialog
 * in front of it is held in tests/Browser, where it can actually be opened.
 *
 * A SECOND OPERATOR AND A REAL CUSTOMER, therefore — and a floor, because "the control was
 * not on the page" must never again be indistinguishable from "the control is guarded".
 */
it('will not suspend a live tenant or a fellow operator without a confirmed act', function (): void {
    polishOperator();

    // Somebody to suspend who is not the person looking. Created through the registry
    // rather than `actAsOperator()`, which would sign us in as THEM — and the control is
    // withheld for the operator doing the looking, which is the whole reason this page
    // rendered nothing to assert about before.
    app(PlatformOperators::class)->create('other-op@platform.test', 'a-strong-operator-pass', 'Other');

    // And a customer, so the two tenant lists have a row with a Suspend control on it.
    app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Suspendable',
        ownerEmail: 'suspendable@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    $lists = [
        'platform.organizations' => ['organizations', 'toggleHref'],
        'platform.operators' => ['operators', 'toggleHref'],
        'platform.customers' => ['customers', 'toggleHref'],
    ];

    $checked = 0;

    foreach ($lists as $route => [$prop, $key]) {
        $rows = collect((array) $this->get(route($route))->assertOk()->inertiaProps($prop))
            // The operator's own row deliberately carries no control, and is not evidence
            // either way.
            ->reject(fn (array $row): bool => ($row['isSelf'] ?? false) === true);

        expect($rows)->not->toBeEmpty($route.' rendered no suspendable row, so this proves nothing about it');

        foreach ($rows as $row) {
            $checked++;

            $url = $row[$key];

            expect($url)->toBeString();

            // A POST, and ONLY a POST. A suspend reachable by GET is a suspend a prefetch,
            // a crawler or a pasted link can perform — which is a confirmation nobody was
            // ever shown.
            $this->get($url)->assertMethodNotAllowed();
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(3, 'a list rendered no Suspend control, so this proved nothing about it');
});

it('says who the operator is signed in as when there is no account to name', function (): void {
    polishOperator();

    // The topbar read "Workspace / Account", the rail foot "Account", the popover
    // "Account" with no address — on a console where one click suspends a customer. The
    // three of them draw from one shared prop, so that is where it is asked.
    $props = (array) $this->get(route('platform.environments'))->assertOk()->inertiaProps();

    $auth = (array) $props['auth'];
    $shell = (array) $props['shell'];

    expect($auth['user']['email'])->toBe('polish-op@platform.test')
        // No organization to name — which is the whole persona: an operator who runs the
        // deployment and buys nothing on it has no membership row.
        ->and($auth['organization'])->toBeNull()
        // …so the console says what they ARE instead of leaving the space blank. Drawn from
        // this flag and from nothing else; the pill itself is held in tests/Browser.
        ->and($shell['isOperator'])->toBeTrue();
});

it('shows an account owner nothing platform-shaped', function (): void {
    polishOwner();

    $html = (string) $this->get(route('projects'))->assertOk()->getContent();

    expect($html)->not->toContain(route('platform.environments'))
        ->and($html)->not->toContain('Platform operator');

    // And the pages themselves refuse, not merely hide.
    $this->get(route('platform.environments'))->assertNotFound();
});

/**
 * THE LISTS ARE TABLES, and the search announces its own result count.
 *
 * Header and body used to be two independent grids over the same `fr` tracks: measured on
 * /platform/customers, the data under "Created" sat 121px left of its own heading, because
 * the header's last cell was an empty span and the body's a button cluster. A table cannot
 * disagree with itself, and it gives a screen reader the column association too.
 *
 * That is markup, so it is held where markup can be seen — tests/Browser. What survives
 * here is the SOURCE contract every ported list shares: a `<Table>` with a caption, column
 * headers, and the `<output>` that reports a filter narrowing to nothing (SC 4.1.3). The
 * generic version of this sweep lives in tests/Feature/AccessibilityTest; this one names
 * the platform pages specifically, because they are the ones that had neither.
 */
it('renders the platform lists as captioned tables that announce their result count', function (): void {
    $pages = [
        'console/platform/environments.tsx',
        'console/platform/customers.tsx',
        'console/platform/organizations.tsx',
    ];

    foreach ($pages as $page) {
        $source = file_get_contents(resource_path('js/pages/'.$page)) ?: '';

        expect($source)->toContain('<Table')
            // The caption is REQUIRED by the primitive, but a page can still pass an empty
            // one — "table, 6 columns, 40 rows" and nothing else is what this prevents.
            ->and($source)->toContain('caption="')
            ->and($source)->toContain('<Th>')
            ->and($source)->toContain('<output');
    }

    // And the cross-plane search, which is not a table but has the same duty to say how
    // many results a term returned.
    expect(file_get_contents(resource_path('js/pages/console/platform/search.tsx')) ?: '')
        ->toContain('<output');
});
