<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\SelfServiceSignup;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\AccessControl\Manifest\DeclaredPermission;
use Cbox\Id\AccessControl\Manifest\DeclaredRole;
use Cbox\Id\AccessControl\Manifest\Manifest;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\Mail;

/**
 * A CUSTOMER ENVIRONMENT'S DOORS, AS ITS END USERS SEE THEM.
 *
 * tests/Feature/TenantDoorBrandingTest.php holds that the doors are handed the
 * environment's brand; this looks at what the sign-in layout draws with it — the vendor's
 * name over the form, and none of Cbox's pitch beside it — in both themes and on a phone.
 */
beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    Mail::fake();
});

/** The SaaS shape and one vendor environment called cboxtax on this server's host, sign-up open. */
function tenantDoorWorld(): void
{
    multiTenantDeployment();
    platformRootEnvironment();

    $vendor = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Cboxtax',
        ownerEmail: 'owner@vendor.test',
        ownerName: 'Vendor Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    $environment = serveOnTestHost($vendor->environment);
    $environment->forceFill(['name' => 'cboxtax'])->save();

    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    app(SelfServiceSignup::class)->set($environment, true);
}

it('draws a customer environment\'s sign-up in its name, with none of Cbox\'s pitch', function (): void {
    tenantDoorWorld();

    $page = visit('/signup');

    $page->assertSee('Create your organization')
        ->assertSee('Sign up for cboxtax')
        ->assertDontSee('Set up Cbox ID')
        ->assertDontSee('SCIM 2.0 directory provisioning')
        ->assertDontSee('self-hostable')
        ->assertScript('document.querySelector("aside.auth-hero") === null', true)
        ->assertTitleContains('cboxtax')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'tenant-door-signup');

    $page->inDarkMode()->screenshot(filename: 'tenant-door-signup-dark');

    $page->resize(375, 812)
        ->assertSee('Sign up for cboxtax')
        // Nothing wider than the phone: the page scrolls down, never sideways.
        ->assertScript('document.documentElement.scrollWidth <= 375', true)
        ->screenshot(filename: 'tenant-door-signup-mobile');
});

it('draws the sign-in and the password reset there the same way', function (): void {
    tenantDoorWorld();

    foreach (['/login' => 'tenant-door-login', '/forgot-password' => 'tenant-door-forgot-password'] as $path => $shot) {
        visit($path)
            ->assertSee('cboxtax')
            ->assertDontSee('SAML & OIDC single sign-on')
            ->assertScript('document.querySelector("aside.auth-hero") === null', true)
            ->assertNoJavaScriptErrors()
            ->screenshot(filename: $shot);
    }
});

it('keeps Cbox\'s own panel on a single-tenant install\'s sign-up', function (): void {
    installedDeployment();

    visit('/signup')
        ->assertSee('Set up Cbox ID for your team')
        ->assertSee('SCIM 2.0 directory provisioning')
        ->assertNoJavaScriptErrors();
});

it('shows the invitee the app role beside the built-in one, in both themes and on a phone', function (): void {
    tenantDoorWorld();

    $client = app(ClientRegistry::class)->register(new NewClient(
        'cboxtax',
        ClientType::Confidential,
        redirectUris: ['https://app.cboxtax.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        firstParty: true,
    ))->client;

    app(AppManifests::class)->sync($client->client_id, new Manifest(
        version: '1',
        permissions: [new DeclaredPermission('tax:quote', 'Get a quote', true)],
        roles: [new DeclaredRole('editor', 'Editor', null, ['tax:quote'])],
    ));

    $anna = app(Subjects::class)->create('anna@hansen.test', 'Anna Hansen', 'a-strong-unbreached-passphrase');
    $team = app(Organizations::class)->create(new NewOrganization('Hansen Revision', 'hansen-revision'));
    app(Memberships::class)->add($team->id, $anna->id, MembershipRole::Owner);

    app(OrganizationInvitations::class)->send(new NewInvitation(
        organizationId: $team->id,
        email: 'bo@nordic-survey.test',
        role: MembershipRole::Member,
        inviter: new Inviter($anna->id, 'Anna Hansen'),
        accessRoleIds: [Role::query()->where('client_id', $client->client_id)->where('key', 'editor')->sole()->id],
        clientId: $client->client_id,
    ));

    $page = visit((string) parse_url((string) Mail::sent(InvitationMail::class)->last()?->url, PHP_URL_PATH));

    $page->assertSee('Join Hansen Revision?')
        ->assertSee('Built-in role')
        ->assertSee('Member')
        ->assertSee('Roles in cboxtax')
        ->assertSee('Editor')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'join-with-app-roles');

    $page->inDarkMode()->screenshot(filename: 'join-with-app-roles-dark');

    $page->resize(375, 812)
        ->assertSee('Roles in cboxtax')
        ->assertScript('document.documentElement.scrollWidth <= 375', true)
        ->screenshot(filename: 'join-with-app-roles-mobile');
});
