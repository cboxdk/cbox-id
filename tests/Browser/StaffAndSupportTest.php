<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentSudo;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\SupportSessions;
use Cbox\Id\OAuthServer\Enums\SupportActorKind;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\OAuthServer\ValueObjects\NewSupportSession;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/**
 * STAFF ROLES AND SUPPORT ACCESS, DRAWN.
 *
 * The feature suite proves what the server does with each request; it cannot see whether
 * the Staff page's picker opens, whether a refusal is printed where somebody will read it,
 * or whether "Sign in to Parcels as Grace" is on the page at all. These drive the pages in
 * a browser, in both themes and at phone width.
 */
beforeEach(function (): void {
    installedDeployment();
});

/**
 * An environment administrator, an organization with Grace in it, the Parcels app with a
 * staff-only Support role, and Sam, who already holds it.
 *
 * @return array{org: string, grace: string, sam: string, parcels: string, approver: string}
 */
function staffAndSupportWorld(): array
{
    actAsEnvironmentAdminOfATenant();

    $org = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-browser'));
    $grace = app(Subjects::class)->create('grace@globex.test', 'Grace Hopper', 'a-strong-unbreached-passphrase')->id;
    app(Memberships::class)->add($org->id, $grace, MembershipRole::Member);

    $sam = app(Subjects::class)->create('sam@vendor.test', 'Sam Support', 'a-strong-unbreached-passphrase')->id;
    app(Memberships::class)->add($org->id, $sam, MembershipRole::Member);

    $parcels = app(ClientRegistry::class)->register(new NewClient(
        name: 'Parcels',
        redirectUris: ['https://parcels.test/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid', 'email'],
        firstParty: true,
    ))->client->client_id;

    $roles = app(Roles::class);
    $support = $roles->define(null, 'Support', clientId: $parcels, tenantAssignable: false);
    $auditor = $roles->define(null, 'Auditor');
    $approver = $roles->define(null, 'Approver');
    app(SegregationOfDuties::class)->definePolicy(null, 'Approve or audit', [$approver->id, $auditor->id]);

    $roles->assignEverywhere($sam, $support->id);
    $roles->assign($org->id, $grace, $approver->id);

    return ['org' => $org->id, 'grace' => $grace, 'sam' => $sam, 'parcels' => $parcels, 'approver' => $approver->id];
}

/** A support session already open for Grace in Parcels. */
function openSupportSession(array $world): void
{
    app(SupportSessions::class)->begin(new NewSupportSession(
        actorId: (string) app(EnvironmentAdminAuth::class)->subjectId(),
        actorKind: SupportActorKind::EnvironmentAdmin,
        targetUserId: $world['grace'],
        organizationId: $world['org'],
        clientId: $world['parcels'],
        reason: 'Ticket 4411: invoice totals look wrong',
        ttlSeconds: 1800,
    ));
}

it('lists staff grants per app and grants one from the Staff page', function (): void {
    staffAndSupportWorld();

    $page = visit('/admin/staff');

    $page->assertSee('A staff role is a role you grant to your own people')
        ->assertSee('Parcels')
        ->assertSee('Sam Support')
        ->assertSee('Staff-only')
        ->screenshot(filename: 'staff-page')
        ->fill('email', 'grace@globex.test')
        ->click('Choose a role…')
        ->click('[role="option"]:has-text("Auditor")')
        ->click('Grant')
        // Grace holds Approver in Globex, and the rule names the pair — so the refusal
        // must say WHERE, printed under the field somebody is looking at.
        ->assertSee('In Globex: Blocked by "Approve or audit"')
        ->screenshot(filename: 'staff-refusal')
        ->assertNoJavaScriptErrors();

    visit('/admin/staff')->inDarkMode()->assertSee('Sam Support')->screenshot(filename: 'staff-page-dark');
    visit('/admin/staff')->resize(375, 812)->assertSee('Sam Support')->screenshot(filename: 'staff-page-phone');
});

it('draws a user\'s staff roles and the support access form', function (): void {
    $world = staffAndSupportWorld();

    visit('/admin/users/'.$world['sam'])
        ->assertSee('Staff roles')
        ->assertSee('Support')
        ->assertSee('Take back')
        ->screenshotElement('section:has(h2:has-text("Staff roles"))', 'user-staff-roles');

    $page = visit('/admin/users/'.$world['grace']);

    $page->assertSee('Support access')
        ->assertSee('Sign in to Parcels as Grace Hopper')
        ->screenshotElement('section:has(h2:has-text("Support access"))', 'user-support-access')
        ->fill('reason', 'Ticket 4411: invoice totals look wrong')
        ->click('Sign in to Parcels as Grace Hopper')
        // A fresh password first — the page asks, rather than the button being hidden.
        ->assertSee('Signing in to an app as this person lets you act as them there.')
        ->assertNoJavaScriptErrors();

    visit('/admin/users/'.$world['grace'])->inDarkMode()->assertSee('Support access')
        ->screenshotElement('section:has(h2:has-text("Support access"))', 'user-support-access-dark');
    visit('/admin/users/'.$world['grace'])->resize(375, 812)->assertSee('Support access')
        ->screenshotElement('section:has(h2:has-text("Support access"))', 'user-support-access-phone');
});

it('lists an open support session on the person\'s and the organization\'s page, and ends it', function (): void {
    $world = staffAndSupportWorld();
    openSupportSession($world);
    app(EnvironmentSudo::class)->confirm();

    visit('/admin/users/'.$world['grace'])
        ->assertSee('Support session')
        ->assertSee('Ticket 4411: invoice totals look wrong')
        ->screenshotElement('section:has(h2:has-text("Support access"))', 'user-open-session');

    $page = visit('/admin/organizations/'.$world['org']);

    $page->assertSee('Support sessions')
        ->assertSee('Grace Hopper')
        ->screenshotElement('section:has(h2:has-text("Support sessions"))', 'organization-open-session')
        ->click('End now')
        ->assertSee('End the support session for Grace Hopper in Parcels?')
        ->click('[role="dialog"] button:has-text("End now")')
        ->assertSee('Support session ended')
        ->assertSee('Nobody is signed in to an app as one of its people')
        ->assertNoJavaScriptErrors();

    openSupportSession($world);
    visit('/admin/organizations/'.$world['org'])->inDarkMode()->resize(375, 812)->assertSee('Support sessions')
        ->screenshotElement('section:has(h2:has-text("Support sessions"))', 'organization-sessions-phone-dark');
});

it('opens a review of staff roles', function (): void {
    staffAndSupportWorld();

    $page = visit('/admin/access-reviews/new?review=staff');

    $page->assertSee('What to review')
        ->assertSee('Snapshots every staff role')
        ->screenshot(filename: 'review-staff-create')
        ->fill('name', 'Q3 staff access')
        ->click('Open review')
        ->assertSee('Q3 staff access')
        ->assertSee('Staff role')
        ->assertSee('Sam Support')
        ->screenshot(filename: 'review-staff-show')
        ->assertNoJavaScriptErrors();

    visit('/admin/access-reviews')->inDarkMode()->assertSee('Staff roles')->screenshot(filename: 'review-list-dark');
});
