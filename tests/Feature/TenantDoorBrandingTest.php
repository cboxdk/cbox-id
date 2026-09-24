<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\SelfServiceSignup;
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
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| A customer environment's doors are the customer's
|--------------------------------------------------------------------------
| A vendor's end users signing up on the vendor's own host met Cbox ID's name, monogram
| and sales pitch beside the form — SCIM and hash-chained audit, offered to somebody who
| came to file a tax return. Every door on a customer environment is painted in that
| environment's brand (the name and logo its Appearance page previews), and the platform
| root's own doors keep Cbox's.
|
| Asserted on the `brand` prop because that one prop is what the sign-in layout draws the
| hero from: null draws Cbox's panel, a brand draws the customer's page and no panel. The
| pictures are in tests/Browser/AuthPagesTest.php.
*/

const DOOR_LOGO = 'https://cdn.cboxtax.test/logo.svg';
const DOOR_VERIFIER = 'a-door-branding-verifier-of-sufficient-length-0123456789';

beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    Mail::fake();
});

/** The SaaS shape, one vendor environment on this suite's host, named and with a logo. */
function brandedDoorEnvironment(): Environment
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
    $environment->forceFill([
        'name' => 'cboxtax',
        // A freshly provisioned environment has no settings yet; this is its Appearance
        // page's logo field, saved.
        'settings' => ['brand_logo_url' => DOOR_LOGO],
    ])->save();

    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    app(SelfServiceSignup::class)->set($environment, true);

    return $environment;
}

function doorClient(): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        'cboxtax',
        ClientType::Public,
        redirectUris: ['https://app.cboxtax.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid', 'profile'],
        firstParty: true,
    ))->client->client_id;
}

function doorAuthorize(string $clientId, string $prompt): TestResponse
{
    return test()->get(route('oauth.authorize', [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.cboxtax.test/cb',
        'scope' => 'openid profile',
        'state' => 'st',
        'code_challenge' => pkcePair(DOOR_VERIFIER)['challenge'],
        'code_challenge_method' => 'S256',
        'prompt' => $prompt,
    ]));
}

/** The page is the environment's: its name and logo, in the tab title too. */
function assertEnvironmentDoor(TestResponse $response, string $component): void
{
    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component($component)
            ->where('brand', ['name' => 'cboxtax', 'logo' => DOOR_LOGO]));

    // The first paint's title, before any script runs.
    expect((string) $response->getContent())->toMatch('#<title>[^<]*· cboxtax</title>#')
        ->and((string) $response->getContent())->not->toMatch('#<title>[^<]*Cbox ID</title>#');
}

it('paints every door on a customer environment in its brand', function (): void {
    brandedDoorEnvironment();
    $clientId = doorClient();

    assertEnvironmentDoor(test()->get(route('login')), 'auth/login');
    assertEnvironmentDoor(test()->get(route('signup')), 'auth/signup');
    assertEnvironmentDoor(test()->get(route('password.request')), 'auth/forgot-password');
    assertEnvironmentDoor(test()->get(route('password.reset', 'a-token')), 'auth/reset-password');
    assertEnvironmentDoor(test()->get(route('magic.redeem', 'a-token')), 'auth/confirm-sign-in');

    // An invitation into a team there.
    $anna = app(Subjects::class)->create('anna@hansen.test', 'Anna Hansen', 'a-strong-unbreached-passphrase');
    $team = app(Organizations::class)
        ->create(new NewOrganization('Hansen Revision', 'hansen-revision'));
    app(Memberships::class)->add($team->id, $anna->id, MembershipRole::Owner);

    app(OrganizationInvitations::class)->send(new NewInvitation(
        organizationId: $team->id,
        email: 'bo@nordic.test',
        role: MembershipRole::Member,
        inviter: new Inviter($anna->id, 'Anna Hansen'),
    ));

    preg_match('#/invitations/([^/?]+)/accept#', (string) Mail::sent(InvitationMail::class)->last()?->url, $link);

    assertEnvironmentDoor(test()->get(route('invitation.accept', $link[1] ?? '')), 'auth/join-organization');

    // Signed in, the app's hosted organization picker and create-a-team step.
    attemptLogin(['email' => 'anna@hansen.test', 'password' => 'a-strong-unbreached-passphrase'])->assertSessionHasNoErrors();

    assertEnvironmentDoor(test()->get((string) doorAuthorize($clientId, 'select_organization')->headers->get('Location')), 'oauth/organization');
    assertEnvironmentDoor(test()->get((string) doorAuthorize($clientId, 'create_organization')->headers->get('Location')), 'oauth/create-organization');
});

it('leaves the console on that host in Cbox\'s own name', function (): void {
    brandedDoorEnvironment();

    $anna = app(Subjects::class)->create('anna@hansen.test', 'Anna Hansen', 'a-strong-unbreached-passphrase');
    $team = app(Organizations::class)->create(new NewOrganization('Hansen Revision', 'hansen-revision'));
    app(Memberships::class)->add($team->id, $anna->id, MembershipRole::Owner);

    attemptLogin(['email' => 'anna@hansen.test', 'password' => 'a-strong-unbreached-passphrase'])->assertSessionHasNoErrors();

    // The console is Cbox's product with Cbox's chrome; a tab titled after the vendor over
    // it would be the opposite mistake.
    $people = test()->get(route('directory.members'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('brand', null));

    expect((string) $people->getContent())->toMatch('#<title>[^<]*· Cbox ID</title>#');
});

it('keeps Cbox\'s own panel on the platform root\'s sign-up', function (): void {
    brandedDoorEnvironment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);

    // Unbranded: the layout draws Cbox's hero, which is the root's to show.
    $root = test()->get('https://cboxid.com/signup')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/signup')
            ->where('createsIdp', true)
            ->where('brand', null));

    expect((string) $root->getContent())->toMatch('#<title>[^<]*· Cbox ID</title>#');
});

it('keeps a single-tenant install\'s doors as its operator named them', function (): void {
    installedDeployment();

    // There the one environment IS the deployment, named through cbox-id.branding.*; an
    // environment name is not a brand anybody chose.
    test()->get(route('login'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('brand', null));
});

it('says the brand, not a logo that is not there, when none was uploaded', function (): void {
    $environment = brandedDoorEnvironment();
    $environment->refresh()->forceFill(['settings' => array_diff_key($environment->settings, ['brand_logo_url' => true])])->save();

    test()->get(route('signup'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('brand', ['name' => 'cboxtax', 'logo' => null]));
});
