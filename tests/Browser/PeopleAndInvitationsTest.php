<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * THE INVITE FORM, THE PENDING LIST AND THE OWNERSHIP CONTROLS, DRIVEN IN A BROWSER.
 *
 * The feature suite proves what the server does with each request. It cannot see whether
 * the one shared invite form is drawn on a page, whether "Send them to an app afterwards"
 * opens, or whether "Transfer ownership" is reachable from a row at all — a control that is
 * never drawn passes every request-level test there is.
 */
beforeEach(function (): void {
    installedDeployment();
    Mail::fake();
});

/**
 * An owner of an ordinary (non-customer) organization with one colleague and one app.
 *
 * @return array{0: Organization, 1: string} the organization, and the colleague's id
 */
function anOwnerWithATeam(): array
{
    platformRootEnvironment();

    [$owner, $org, $danaId] = app(PlatformRoot::class)->run(function (): array {
        $owner = app(Subjects::class)->create('olivia@people.test', 'Olivia Owner', 'a-strong-unbreached-passphrase');
        app(Subjects::class)->markEmailVerified($owner->id, 'olivia@people.test');

        $org = app(Organizations::class)->create(new NewOrganization('Acme People', 'acme-people-browser'));
        app(Memberships::class)->add($org->id, $owner->id, MembershipRole::Owner);

        $dana = app(Subjects::class)->create('dana@people.test', 'Dana', 'a-strong-unbreached-passphrase');
        app(Memberships::class)->add($org->id, $dana->id, MembershipRole::Member);

        app(ClientRegistry::class)->register(new NewClient(
            'Acme Tasks',
            redirectUris: ['https://tasks.acme.test/auth/callback'],
            organizationId: $org->id,
        ));

        return [$owner, $org, $dana->id];
    });

    signInAsMember($owner->id);

    return [$org, $danaId];
}

it('invites someone with the shared form, back to an app, and lists them with both answers', function (): void {
    anOwnerWithATeam();

    $page = visit('/directory/members');

    $page->assertSee('Everyone who can sign in to this organization')
        ->click('Invite member')
        ->assertSee('Invite someone')
        ->fill('email', 'newbie@people.test')
        ->click('Send them to an app afterwards…')
        ->assertSee('After they accept')
        ->click('This console')
        ->click('[role="option"]:has-text("Acme Tasks")')
        ->assertSee('A page on https://tasks.acme.test')
        ->fill('return_to', 'https://tasks.acme.test/welcome')
        ->screenshot(filename: 'people-invite-form');

    $page->click('button:has-text("Send invitation")')
        ->assertSee('Invitation sent to newbie@people.test.')
        ->assertSee('Invited, not joined yet')
        ->assertSee('for Acme Tasks')
        ->assertSee('invited by Olivia Owner')
        ->assertSee('Send again')
        ->assertSee('Withdraw')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'people-pending');
})->group('a11y');

it('transfers ownership from a row, and offers the owner a way to leave', function (): void {
    [$org, $danaId] = anOwnerWithATeam();

    $page = visit('/directory/members');

    // The owner's own row: no role picker to demote themselves with, and a way out.
    $page->assertSee('Leave')
        ->click('[aria-label="More actions for Dana"]')
        ->assertSee('Transfer ownership')
        ->assertSee('Remove from organization')
        ->click('[role="menuitem"]:has-text("Transfer ownership")')
        ->assertSee('Transfer ownership to dana@people.test?')
        ->assertSee('Only the new owner can hand it back.')
        ->screenshot(filename: 'people-transfer-dialog');

    $page->assertDisabled('.cbx-dialog button:has-text("Transfer ownership")')
        ->fill('.cbx-dialog input.input', 'dana@people.test')
        ->click('.cbx-dialog button:has-text("Transfer ownership")')
        ->assertSee('Ownership transferred to Dana. You are now an admin.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'people-after-transfer');

    expect(app(PlatformRoot::class)->run(fn () => app(Memberships::class)->of($org->id, $danaId)?->role))
        ->toBe(MembershipRole::Owner);
})->group('a11y');

it('draws the People page at phone width without a sideways scroll', function (): void {
    anOwnerWithATeam();

    $page = visit('/directory/members')->resize(375, 812);

    $page->assertSee('Everyone who can sign in to this organization')
        ->click('Invite member')
        ->assertSee('Invite someone')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->screenshot(filename: 'people-mobile');
})->group('a11y');

it('opens an invitation on a page that says who is inviting whom, and joins on the button', function (): void {
    [$org] = anOwnerWithATeam();

    $sent = app(PlatformRoot::class)->run(fn () => app(OrganizationInvitations::class)->send(new NewInvitation(
        organizationId: $org->id,
        email: 'joiner@people.test',
        role: MembershipRole::Member,
        inviter: new Inviter(null, 'Olivia Owner'),
    )));

    // The token rides in the mailed link; read it back off the fake.
    $url = (string) Mail::sent(InvitationMail::class)->last()?->url;
    $path = (string) parse_url($url, PHP_URL_PATH);

    expect($sent)->not->toBeNull();

    $page = visit($path);

    $page->assertSee('Join Acme People?')
        ->assertSee('Invited by')
        ->assertSee('Olivia Owner')
        ->assertSee('Member')
        ->assertSee('joiner@people.test')
        ->assertSee('nothing happens unless you accept')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'join-organization');

    $page->click('button:has-text("Accept invitation")')
        ->assertSee('Invitation accepted — welcome aboard.')
        ->assertNoJavaScriptErrors();

    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('joiner@people.test')))->not->toBeNull();
})->group('a11y');

it('offers the owner "Delete organization" under Settings', function (): void {
    anOwnerWithATeam();

    visit('/settings')
        ->assertSee('Delete organization')
        ->click('button:has-text("Delete organization")')
        ->assertSee('Delete Acme People?')
        ->assertDisabled('.cbx-dialog button:has-text("Delete")')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'settings-delete-organization');
})->group('a11y');

it('draws the same invite form on the environment console, with "Make owner" on a row', function (): void {
    actAsEnvironmentAdminOfATenant();

    $org = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co-browser'));
    $erin = app(Subjects::class)->create('erin@tenant.test', 'Erin');
    app(Memberships::class)->add($org->id, $erin->id, MembershipRole::Member);

    $page = visit('/admin/organizations/'.$org->id);

    $page->assertSee('Invite someone')
        ->assertSee('Send invitation')
        ->click('[aria-label="More actions for Erin"]')
        ->assertSee('Make owner')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'environment-organization');
})->group('a11y');

it('draws no staff-only role on the environment console\'s invite form, and does on its add-member form', function (): void {
    actAsEnvironmentAdminOfATenant();

    $org = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co-staff-browser'));
    app(Roles::class)->define(null, 'Approver');
    app(Roles::class)->define(null, 'Vendor support', tenantAssignable: false);

    $page = visit('/admin/organizations/'.$org->id)
        ->assertSee('Send invitation')
        ->assertNoJavaScriptErrors();

    // Rendered first (asserted above), then read: the invite form's own text, not the
    // page's, because the add-member form beside it rightly offers the staff role.
    $invite = (string) $page->script('document.querySelector(\'form[aria-label="Invite someone"]\').innerText');

    expect($invite)->toContain('Approver')
        ->and($invite)->not->toContain('Vendor support');

    // Offered on the add-member form, and marked for what it is — in the Staff page's words.
    $page->assertSee('Vendor support')
        ->assertSee('Staff-only')
        ->assertSee('this organization\'s admins can\'t see or grant it')
        ->screenshot(filename: 'environment-organization-staff-roles');

    // Never tagged onto an ordinary role.
    $approver = (string) $page->script('Array.from(document.querySelectorAll(".cbx-check-label")).find((l) => l.textContent === "Approver")?.parentElement?.innerText');

    expect($approver)->not->toContain('Staff-only');

    visit('/admin/organizations/'.$org->id)->inDarkMode()
        ->assertSee('Staff-only')
        ->screenshot(filename: 'environment-organization-staff-roles-dark');

    visit('/admin/organizations/'.$org->id)->resize(375, 812)
        ->assertSee('Staff-only')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->screenshot(filename: 'environment-organization-staff-roles-mobile');
})->group('a11y');

it('draws the same invite form for a customer\'s administrators', function (): void {
    ['subjectId' => $ownerId] = provisionAccount('owner@acme.example');
    signInAsMember($ownerId);

    visit('/team')
        ->assertSee('Invite a teammate')
        ->assertSee('Send invitation')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'account-administrators');
})->group('a11y');

it('names an owner on the environment console\'s user page without offering Owner as a choice', function (): void {
    actAsEnvironmentAdminOfATenant();

    $org = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co-user-browser'));
    $erin = app(Subjects::class)->create('erin@tenant.test', 'Erin');
    app(Memberships::class)->add($org->id, $erin->id, MembershipRole::Owner);

    $page = visit('/admin/users/'.$erin->id);

    $page->assertSee('Tenant Co')
        // The row's picker SHOWS the role it holds…
        ->assertSee('Owner')
        ->assertNoJavaScriptErrors()
        ->script("document.querySelector('[aria-label=\"Built-in role in Tenant Co\"]').scrollIntoView({block: 'center'})");

    $page->screenshot(fullPage: false, filename: 'environment-user');

    // …and does not offer it: the option is there, disabled.
    $page->click('[aria-label="Built-in role in Tenant Co"]')
        ->assertScript('document.querySelector(\'[role="option"][data-disabled]\')?.textContent.includes("Owner") ?? false', true)
        ->screenshot(fullPage: false, filename: 'environment-user-roles');
})->group('a11y');

it('names who invited a customer\'s administrator, and as what', function (): void {
    ['subjectId' => $ownerId, 'organization' => $account] = provisionAccount('owner@acme.example');

    $pending = app(PlatformRoot::class)->run(
        fn () => app(Invitations::class)
            ->invite($account->id, 'new@acme.example', MembershipRole::Developer, $ownerId),
    );

    $url = URL::temporarySignedRoute('organization.invite.accept', now()->addDay(), ['token' => $pending->token]);

    visit((string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY))
        ->assertSee('Owner')
        ->assertSee('invited you to help run')
        ->assertSee('Developer')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'account-accept-invite');
})->group('a11y');
