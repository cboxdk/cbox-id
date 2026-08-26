<?php

declare(strict_types=1);

use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\ValueObjects\NewServiceProvider;

/**
 * ACCESSIBILITY, IN A REAL BROWSER.
 *
 * The sweep this replaces ran axe over the SERVER's HTML inside jsdom, which was the right
 * tool for a server-rendered console and is the wrong one for a client-rendered page:
 * there is nothing in the response but a mount point, so it audits an empty document and
 * reports no violations — the shape of green the guard's own docblock warns about.
 *
 * It is also strictly better coverage, not merely equivalent. jsdom has no layout engine
 * and no cascade, so the old bridge had to disable `color-contrast` outright — the single
 * rule this design system's tokens are most carefully tuned for, and the one that has
 * actually regressed here before. A real browser computes it.
 *
 * The jsdom sweep stays for the pages still served by Volt, and shrinks as they port.
 */
beforeEach(function (): void {
    installedDeployment();
});

/**
 * A subject with a session the BROWSER will carry, not only this process, in an
 * environment with something in it.
 *
 * The page drives its own request, so the session id has to be in the cookie jar that
 * request uses — putting it in the session store alone signs nobody in.
 *
 * AND THE ROWS MATTER. Every list below would otherwise be audited against its own empty
 * state: the invitation panel, the paginator and the roster row are markup this sweep has
 * never seen, and an audit of an empty page is the shape of green that means nothing. The
 * world is built inside `PlatformRoot::run()` because that is where the console reads it
 * from — seeded in the ambient scope, the pages render empty and the audit is back to
 * auditing nothing.
 */
function signedInForAudit(): void
{
    platformRootEnvironment();

    $subject = app(PlatformRoot::class)->run(function () {
        $subject = app(Subjects::class)->create('a11y-browser@acme.test', 'Audit Owner', 'supersecret123');
        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-a11y-browser'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

        // A PRODUCT: the Identity-platform pages belong to a CUSTOMER, and an
        // organization that owns none is refused them.
        app(Projects::class)->createForOrganization($org->id, 'Acme');

        // One invitation nobody has accepted, and one more member than a page holds — so
        // the panel and the pager are both on screen when axe runs.
        app(Invitations::class)->invite($org->id, 'pending@acme.test', MembershipRole::Member, null);

        foreach (range(1, 26) as $i) {
            $member = app(Subjects::class)->create("a11y-member-{$i}@acme.test", "Member {$i}");
            app(Memberships::class)->add($org->id, $member->id, MembershipRole::Member);
        }

        return $subject;
    });

    signInAsMember($subject->id);

    /*
     * AND THE STEP-UP WINDOW. The token vault is behind `sudo` on every route, reads
     * included, so without this the sweep would audit the step-up screen and report the
     * vault as accessible. Opening it here is not a weakening: this sweep is about what a
     * page RENDERS, and the gate has its own coverage in ConsoleStepUpTest.
     */
    app(Sudo::class)->confirm();
}

it('has no accessibility issues on the sign-in surfaces', function (string $path): void {
    if ($path === '__reset__') {
        app(Subjects::class)->create('reset-browser@acme.test', 'R', 'super-secret-1234');
        $path = '/reset-password/'.app(PasswordReset::class)->request('reset-browser@acme.test');
    }

    visit($path)
        ->assertNoAccessibilityIssues()
        // A page that threw during render is a page axe found nothing wrong with.
        ->assertNoJavaScriptErrors();
})->with([
    'login' => '/login',
    'signup' => '/signup',
    'forgot-password' => '/forgot-password',
    'reset-password' => '__reset__',
])->group('a11y');

/**
 * The hosted surface had no landmarks at all: its layout carried neither a <main> nor a
 * skip link, while every other layout in the repo carried both. That covers sign-in, MFA,
 * passkeys, consent and device approval — the pages a tenant's own users see, and the ones
 * this platform is judged on.
 *
 * Asserted on the RENDERED page rather than on the response body, because the skip link
 * comes from the root view and the landmark from React, and the promise is about the
 * document a person actually gets.
 */
it('gives every ported surface one main landmark and a skip link', function (string $path): void {
    visit($path)
        ->assertPresent('#main-content')
        ->assertPresent('a[href="#main-content"]')
        // EXACTLY ONE. Two `<main>` elements is not a landmark, it is an ambiguity, and
        // the skip link can then only reach one of them. Counted in the page rather than
        // through a selector assertion, which waits for the FIRST match and cannot
        // therefore report a second.
        ->assertScript('document.querySelectorAll("main").length', 1);
})->with([
    'login' => '/login',
    'signup' => '/signup',
    'forgot-password' => '/forgot-password',
])->group('a11y');

it('has no accessibility issues on the ported console pages', function (string $path, string $heading): void {
    signedInForAudit();

    visit($path)
        /*
         * THE PAGE'S OWN HEADING, FIRST.
         *
         * A console page that bounced to /login is a page axe reports no violations on,
         * and so is one that 403'd — both are perfectly accessible documents about
         * something else. Naming the h1 is what makes the audit below an audit OF THIS
         * PAGE rather than of whatever the browser happened to land on.
         */
        ->assertSee($heading)
        ->assertNoAccessibilityIssues()
        // A page that threw during render is a page axe found nothing wrong with.
        ->assertNoJavaScriptErrors();
})->with([
    'projects' => ['/projects', 'Projects'],
    'members' => ['/members', 'Administrators'],
    'api-keys' => ['/api-keys', 'API keys'],
    'webhooks' => ['/webhooks', 'Webhooks'],
    'audit' => ['/audit', 'Activity log'],
    'settings' => ['/settings', 'Settings'],
    'appearance' => ['/appearance', 'Appearance'],
    'sign-in-rules' => ['/sign-in-rules', 'Sign-in rules'],
    'clients' => ['/clients', 'Apps & API keys'],
    'connections' => ['/connections', 'Single sign-on'],
    'directories' => ['/directories', 'Sync users in'],
    'roles' => ['/roles', 'Roles'],
    'permissions' => ['/permissions', 'Permissions'],
    'hooks' => ['/hooks', 'Inline hooks'],
    'access-reviews' => ['/governance', 'Access reviews'],
    'role-conflicts' => ['/sod-policies', 'Role conflicts'],
    'vault' => ['/vault', 'Token vault'],
    'outbound-sync' => ['/provisioning', 'Sync users out'],
    'log-streams' => ['/log-streaming', 'Log streaming'],
    'usage' => ['/usage', 'Usage'],
    'social-providers' => ['/social-providers', 'Social sign-in'],
    'get-started' => ['/get-started', 'Set up Acme'],
    'approvals' => ['/approvals', 'Agent approvals'],
    'dashboard' => ['/dashboard', 'Welcome back'],
])->group('a11y');

/**
 * THE AUDIT IS ONLY WORTH ANYTHING IF THE PAGE DREW THE THING UNDER AUDIT.
 *
 * The roster's invitation panel and its paginator were absent from the audited document
 * for the life of the sweep this replaces — seeded in one scope and read in another. They
 * are asserted here, on the rendered page, rather than trusted.
 */
it('audits a roster that actually has rows, an invitation and a pager', function (): void {
    signedInForAudit();

    visit('/members')
        ->assertSee('Invited, not joined yet')
        ->assertSee('pending@acme.test')
        ->assertSee('Next')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->group('a11y');

/**
 * THE ENVIRONMENT PLANE, in a real browser.
 *
 * The jsdom sweep held these until they ported. It cannot hold them any longer — the
 * response for a ported page is a mount point and an audit of it reports no violations —
 * so the coverage moves here rather than quietly lapsing, which is the exact failure the
 * jsdom guard's own docblock warns about.
 *
 * `/admin` exists only on the multi-tenant shape, and only on a host that resolves to a
 * tenant's environment, so the fixture states both.
 */
function environmentAdminForAudit(): void
{
    actAsEnvironmentAdminOfATenant();

    // Something on every list, so the audit is of a page with rows rather than of five
    // empty states. Written in the environment's own scope, which is where the console
    // reads it from.
    $subject = app(Subjects::class)->create('a11y-env-user@acme.test', 'Env User');
    $org = app(Organizations::class)->create(new NewOrganization('Tenant A11y', 'tenant-a11y'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    app(ServiceProviders::class)->register(new NewServiceProvider(
        entityId: 'https://a11y-sp/meta',
        acsUrl: 'https://a11y-sp/acs',
        nameIdFormat: NameIdFormat::cases()[0],
        nameIdAttribute: 'email',
    ));
}

it('has no accessibility issues on the ported environment console pages', function (string $path, string $heading): void {
    environmentAdminForAudit();

    visit($path)
        // The page's own heading first: a console page that bounced or 403'd is a
        // perfectly accessible document about something else.
        ->assertSee($heading)
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->with([
    'home' => ['/admin', 'Overview'],
    'organizations' => ['/admin/organizations', 'Organizations'],
    'users' => ['/admin/users', 'Users'],
    'saml-applications' => ['/admin/login-methods', 'SAML applications'],
    'approvals' => ['/admin/approvals', 'Agent approvals'],
    // The SAME page as `/usage` on the other plane, and the reason that page exists: the
    // environment plane had a primitive copy of these counters called "Analytics".
    'analytics' => ['/admin/analytics', 'Usage'],
    'social-sign-in' => ['/admin/social-sign-in', 'Social sign-in'],
])->group('a11y');

/**
 * SESSIONS & ACTIVITY, with something in all three of its lists.
 *
 * The page whose entire job is "is any of this not me?" — so the audit is worth nothing
 * unless it runs over a page with a second session, an application holding a grant and a
 * trail of events on it. Empty, every one of those lists is an empty state, and the
 * markup this exists to check is not on the page at all.
 */
function accountActivityForAudit(): string
{
    platformRootEnvironment();

    $subject = app(PlatformRoot::class)->run(function () {
        $subject = app(Subjects::class)->create('a11y-activity@acme.test', 'Audit Person', 'supersecret123');
        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-a11y-activity'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

        // A SECOND SESSION, so the roster has a row that is not "this device" and the
        // "sign out everywhere else" control is drawn at all.
        app(SessionManager::class)->start(
            $subject->id,
            $org->id,
            ['pwd'],
            '198.51.100.7',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1',
        );

        // And something holding a refresh token as them — the device-flow case this page
        // exists for, and the row that carries the type-to-confirm withdrawal.
        $client = app(ClientRegistry::class)->register(new NewClient(
            'Acme CLI',
            ClientType::Public,
            grantTypes: ['urn:ietf:params:oauth:grant-type:device_code'],
            scopes: ['openid', 'offline_access'],
        ))->client;

        app(RefreshTokens::class)->issue($client, $subject->id, $org->id, ['openid', 'offline_access']);

        return $subject;
    });

    // Signing in is also what writes the first `user.session_started` entry, so the
    // activity list has a row of its own.
    signInAsMember($subject->id);

    return $subject->id;
}

/**
 * THE SECURITY PAGE, with every panel it has drawn.
 *
 * A passkey, a linked social account and a live second factor: the three panels that are
 * an empty state on a bare account, and the three that carry the page's controls. Audited
 * without them, this page is a name field and two paragraphs.
 */
function accountSecurityForAudit(): void
{
    // The id comes back from the fixture: the subject lives in the platform root, so
    // looking it up out here finds nothing at all — and neither does a credential written
    // out here, which is environment-owned like everything else the console reads.
    $subjectId = accountActivityForAudit();

    app(PlatformRoot::class)->run(function () use ($subjectId): void {
        WebAuthnCredential::query()->create([
            'user_id' => $subjectId,
            'credential_id' => 'a11y-credential',
            'public_key' => 'a11y-public-key',
            'name' => 'Audit laptop',
            'sign_count' => 3,
        ]);
    });

    // A provider has to be CONFIGURED for the panel to exist at all — the page offers only
    // what this deployment can actually sign somebody in with.
    config([
        'services.google.client_id' => 'a11y-client',
        'services.google.client_secret' => 'a11y-secret',
    ]);

    app(PlatformRoot::class)->run(
        fn () => app(Subjects::class)->link($subjectId, new FederatedPrincipal('social:google', 'google|a11y')),
    );
}

it('has no accessibility issues on the security page', function (): void {
    accountSecurityForAudit();

    visit('/account')
        ->assertSee('Profile')
        // The panels, asserted rather than assumed — see the fixture's docblock.
        ->assertSee('Audit laptop')
        ->assertSee('Connected accounts')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('has no accessibility issues on the security page in the dark', function (): void {
    accountSecurityForAudit();

    visit('/account')
        ->inDarkMode()
        ->assertSee('Audit laptop')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('keeps the security page usable at a phone width', function (): void {
    accountSecurityForAudit();

    visit('/account')
        ->on()->mobile()
        ->assertSee('Audit laptop')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('has no accessibility issues on sessions and activity', function (): void {
    accountActivityForAudit();

    visit('/account/activity')
        ->assertSee('Where you are signed in')
        // The rows, asserted rather than assumed — see the fixture's docblock.
        ->assertSee('Safari on iPhone')
        ->assertSee('Acme CLI')
        ->assertSee('Signed in')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('has no accessibility issues on sessions and activity in the dark', function (): void {
    accountActivityForAudit();

    visit('/account/activity')
        ->inDarkMode()
        ->assertSee('Acme CLI')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('keeps sessions and activity usable at a phone width', function (): void {
    accountActivityForAudit();

    visit('/account/activity')
        ->on()->mobile()
        ->assertSee('Acme CLI')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->group('a11y');

/**
 * THE PLATFORM PLANE, in a real browser.
 *
 * Same reason as the environment plane above: the jsdom sweep held these until they
 * ported, and it cannot hold them any longer — a ported page's response is a mount point,
 * and an audit of a mount point reports no violations. The coverage moves here rather than
 * quietly lapsing.
 *
 * AND WITH ROWS ON IT. Every one of these lists is empty on a bare install, and an audit
 * of five empty states is the shape of green that means nothing: the table, the owner
 * column's three states, the roster row and the result list are markup that only exists
 * when there is something to render.
 */
/** @return array{0: string, 1: string} the customer's id, and a tenant's id in the plane */
function operatorForAudit(): array
{
    actAsOperator('a11y-platform@platform.test');
    platformRootEnvironment();

    return app(PlatformRoot::class)->run(function (): array {
        // A second operator, so the roster has a row whose suspend control is drawn — the
        // one the acting operator's own row deliberately does not have.
        app(PlatformOperators::class)->create('a11y-colleague@platform.test', 'a-strong-unbreached-passphrase', 'Ada Lovelace');

        $organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-a11y-platform'));

        /*
         * A TENANT WITH SOMEBODY ON IT, in the plane the console is pointed at — which is
         * what the Organizations pages read. A member whose role is not owner or admin, so
         * the row draws the impersonation form rather than "Not impersonable": that form is
         * the densest control on the plane and the one an empty roster would hide.
         */
        $tenant = app(Organizations::class)->create(new NewOrganization('Tenant A11y', 'tenant-a11y-platform'));
        $member = app(Subjects::class)->create('a11y-search@acme.test', 'Search Me');
        app(Memberships::class)->add($tenant->id, $member->id, MembershipRole::Member);

        // A customer-owned plane AND an unattached one, so the owner column renders all
        // three of its states — a customer link, the platform root, and the leftover.
        $project = app(Projects::class)->createForOrganization($organization->id, 'Acme');
        app(TenantProvisioner::class)->addEnvironment($project, 'Production');

        Environment::query()->create(['name' => 'Leftover', 'slug' => 'a11y-leftover', 'status' => 'active']);

        return [$organization->id, $tenant->id];
    });
}

it('has no accessibility issues on the ported platform console pages', function (string $path, string $heading): void {
    [$organizationId, $tenantId] = operatorForAudit();

    // The customer detail page is the one path in this set that needs an id, and the id is
    // only known once the fixture has run — so the dataset names a placeholder rather than
    // carrying a URL it cannot build.
    $path = str_replace('/first', '/'.$organizationId, $path);
    $path = str_replace('/tenant', '/'.$tenantId, $path);

    visit($path)
        // The page's own heading first: an operator page that 404'd is a perfectly
        // accessible document about something else, and 404 is exactly how this plane
        // refuses.
        ->assertSee($heading)
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->with('platform pages')->group('a11y');

/**
 * Every page on the operator plane, with the heading that proves the browser landed on it.
 *
 * Shared rather than repeated, so the light, dark and phone passes below cannot drift
 * apart — a page added to one and forgotten in the others is coverage that looks complete.
 *
 * `/first` and `/tenant` are placeholders: those two paths need an id the fixture only
 * knows once it has run, and a dataset cannot build a URL it does not have yet.
 */
dataset('platform pages', [
    'environments' => ['/platform', 'Environments'],
    'customers' => ['/platform/customers', 'Customers'],
    // The customer's OWN page, which is where the plane's real density is: a team table, a
    // panel per project, and an environment row with two controls that repoint the console.
    'customer' => ['/platform/customers/first', 'Acme'],
    'organizations' => ['/platform/organizations', 'Organizations'],
    // The tenant's OWN page: a usage grid, a member table with an impersonation form on
    // every row, an entitlement table and an activity table — the densest page on the plane.
    'organization' => ['/platform/organizations/tenant', 'Tenant A11y'],
    'operators' => ['/platform/operators', 'Operators'],
    'usage' => ['/platform/usage', 'Usage'],
    // With a TERM, because the search page with none is a prompt and an empty list — the
    // result rows, which are the markup worth auditing, only exist once it has run.
    'search' => ['/platform/search?term=acme', 'Acme'],
]);

/**
 * THE SAME PAGES IN THE DARK, AND ON A PHONE.
 *
 * COLOUR CONTRAST IS THE WHOLE POINT OF THIS ONE. The sweep these pages came from ran in
 * jsdom, which has no layout engine and no cascade, so it had `color-contrast` disabled —
 * the single rule this design system's tokens are most carefully tuned for. A real browser
 * computes it, and the dark palette is where it goes wrong: it is a second set of tokens
 * that nothing was measuring.
 *
 * The theme follows the OS preference (`prefers-color-scheme`, unless a person has forced
 * light), so asking the browser for dark is asking for the palette a person actually gets.
 *
 * The phone width is the other half. `lg:hidden` and `hidden sm:inline-flex` mean a control
 * can be present in the markup and drawn nowhere, and a table that overflows its container
 * takes the whole page sideways with it.
 */
it('has no accessibility issues on the platform console in the dark', function (string $path, string $heading): void {
    [$organizationId, $tenantId] = operatorForAudit();

    $path = str_replace('/first', '/'.$organizationId, $path);
    $path = str_replace('/tenant', '/'.$tenantId, $path);

    visit($path)
        ->inDarkMode()
        ->assertSee($heading)
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->with('platform pages')->group('a11y');

it('keeps the platform console usable at a phone width', function (string $path, string $heading): void {
    [$organizationId, $tenantId] = operatorForAudit();

    $path = str_replace('/first', '/'.$organizationId, $path);
    $path = str_replace('/tenant', '/'.$tenantId, $path);

    visit($path)
        ->on()->mobile()
        ->assertSee($heading)
        /*
         * THE PAGE DOES NOT SCROLL SIDEWAYS. Every list on this plane is a table, and a
         * table is exactly what takes a phone layout sideways unless it scrolls inside its
         * own container — which is why each one is wrapped in `overflow-x-auto`. Measured
         * rather than assumed: `scrollWidth > clientWidth` on the document is the only
         * thing that can tell the wrapper is missing.
         */
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->with('platform pages')->group('a11y');

/**
 * AND THE ROWS ARE ACTUALLY THERE.
 *
 * The audit above is only worth something if the table drew — an empty flat list is a
 * perfectly accessible page about nothing, and this list's whole reason to exist is the
 * owner column, whose three states (a customer, the platform root, a leftover) are the
 * markup nothing else in the suite can see rendered.
 */
it('audits an environment list whose owner column has all three of its states', function (): void {
    operatorForAudit();

    visit('/platform')
        // The qualified name — "Production" alone is a name half the customers on an
        // install will have.
        ->assertSee('Acme / Production')
        ->assertSee('Platform root')
        ->assertSee('Unattached')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->group('a11y');

/**
 * THE HALVES OF THE OPERATOR CONSOLE NO REQUEST-LEVEL TEST CAN SEE.
 *
 * Two claims in tests/Feature used to be made against markup and became claims about a
 * serialised prop the moment these pages ported — which is to say, about nothing. The
 * server's half is asserted there; this is where what the BROWSER draws is held.
 *
 * The confirmation is the important one. Suspending a live tenant signs out every member
 * of it, and it sat eight pixels from "View" with no dialog, no undo and no toast. The old
 * test grepped three blades for `wire:confirm` and was vacuous for a year because the
 * fixture rendered no Suspend control at all — so this opens the real one.
 */
it('confirms before it suspends a tenant, and says who the operator is', function (): void {
    operatorForAudit();

    $page = visit('/platform/organizations')
        // The page's own content first: `assertPresent` does not wait for React to mount.
        ->assertSee('Tenant A11y')
        // WHAT AUTHORITY YOU ARE HOLDING, drawn on every console page — the topbar used to
        // read "Workspace / Account" on a console where one click suspends a customer.
        ->assertSee('Platform operator');

    // Nothing is suspended by pressing it once.
    // BY ITS ACCESSIBLE NAME, which is also the assertion that it has one: every row on
    // this list carries the same visible word, so "Suspend" alone is ambiguous to a person
    // navigating by control and to this test alike.
    $page->click('button[aria-label="Suspend Tenant A11y"]')
        ->assertSee('Suspend Tenant A11y?')
        ->assertSee('can no longer sign in to this tenant')
        ->assertSee('You can reactivate it here')
        ->assertNoJavaScriptErrors();
})->group('a11y');

/**
 * THE MODULE PAGES, on the plane they are reached from.
 *
 * A module ships its own UI, which is exactly why it needs auditing beside the host's: it
 * is the code least likely to be looked at by whoever is changing the design system. The
 * features have to be switched ON — a module's routes only exist where its feature is,
 * so an audit that did not say this would 404 rather than audit anything.
 */
it('has no accessibility issues on the ported module pages', function (string $path, string $heading): void {
    // Stated here rather than borrowed from a Feature test's helper: this file has to be
    // runnable on its own.
    config([
        'id-analytics.enabled' => true,
        'compliance.enabled' => true,
        'connectors.enabled' => true,
        'id-devices.enabled' => true,
    ]);

    environmentAdminForAudit();

    visit($path)
        ->assertSee($heading)
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->with([
    'connector catalogue' => ['/admin/connectors', 'Catalog'],
    'connections' => ['/admin/connectors/connections', 'Connections'],
])->group('a11y');

/**
 * And the two detail pages, which is where the environment console's real density is: the
 * user page alone carries a password panel, a session list, a membership roster with two
 * kinds of role on every row, and an impersonation form.
 */
it('audits the environment console detail pages, with rows on them', function (): void {
    environmentAdminForAudit();

    $user = User::query()->where('email', 'a11y-env-user@acme.test')->firstOrFail();
    $organization = Organization::query()->where('slug', 'tenant-a11y')->firstOrFail();

    visit('/admin/users/'.$user->id)
        ->assertSee('Security & lifecycle')
        // The roster row is markup nothing else audits — the membership select, the access
        // roles, the remove control.
        ->assertSee('Tenant A11y')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    visit('/admin/organizations/'.$organization->id)
        ->assertSee('Members')
        ->assertSee('a11y-env-user@acme.test')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->group('a11y');

/**
 * THE CHROME, DRAWN — which is the half no request-level test can see.
 *
 * Several assertions in tests/Feature used to reach for markers in the response body: the
 * bottom bar's `data-cbox-mobile-nav`, the account menu's sign-out, the impersonation
 * banner. Every one of them was a statement about a blade shell, and every one of them
 * became a statement about a serialised prop the moment the page ported — which is to say,
 * about nothing. Those tests now assert what the SERVER decides; this is where what the
 * BROWSER draws is held.
 */
it('draws the mobile navigation on both console planes', function (string $path, string $heading): void {
    if (str_starts_with($path, '/admin')) {
        environmentAdminForAudit();
    } else {
        signedInForAudit();
    }

    /*
     * The bar is `lg:hidden`, so it is only drawn at a phone's width — which is the whole
     * point of it, and the reason a desktop-sized audit never saw it.
     *
     * THE PAGE'S OWN CONTENT FIRST, and not for the usual reason. `assertPresent` queries
     * the DOM without waiting for React to mount, so on a client-rendered page it can run
     * against an empty `#app` and report the bar missing — which is what it did here, on a
     * plane that draws it perfectly well. Asserting text first is what waits.
     */
    visit($path)
        ->on()->mobile()
        ->assertSee($heading)
        ->assertPresent('[data-cbox-mobile-nav]')
        // The trigger is named for a screen reader rather than in visible text, so it is
        // the LABEL that is asserted — which is also the thing that matters.
        ->assertPresent('[data-cbox-mobile-nav] [aria-label="Open menu"]')
        ->assertNoJavaScriptErrors();
})->with([
    'organization plane' => ['/dashboard', 'Recent activity'],
    'environment plane' => ['/admin', 'Quick actions'],
])->group('a11y');

/**
 * A person with no organization still gets the two things that ARE theirs: their own
 * security page, and a way out.
 *
 * Nothing rendered a sign-out for them before — the rail needed nav areas and they had
 * none, and the mobile sheet is `lg:hidden`. It is in the account menu now, drawn from the
 * shared auth prop rather than from the navigation, so having no areas cannot take it away.
 */
it('offers a sign-out and a security page to somebody who belongs to nowhere', function (): void {
    platformRootEnvironment();

    $subject = app(PlatformRoot::class)->run(
        fn () => app(Subjects::class)->create('nowhere@acme.test', 'Nobody', 'supersecret123'),
    );

    signInAsMember($subject->id);

    visit('/dashboard')
        ->assertSee("don't belong to an organization here yet")
        // Their own security page, offered by name.
        ->assertSee('Manage security')
        ->assertNoJavaScriptErrors()
        // And the way out, behind the account menu — opened, because a control that is
        // present but unreachable is the failure this is about. The trigger is the rail's
        // own item, named for the person it belongs to.
        ->click('button.cbx-railitem')
        ->assertSee('Sign out');
})->group('a11y');
