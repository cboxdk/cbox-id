<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/**
 * THE HOSTED ORGANIZATION STEPS, DRAWN.
 *
 * The feature suite proves what each step binds; it cannot see whether a person can read
 * and press them. These render the picker, the create step, the consent screen that follows
 * and the environment's sign-up switch in a real browser — light, dark and at a phone
 * width — and walk the picker and the create step through to the consent screen.
 */
beforeEach(function (): void {
    installedDeployment();
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
});

/** Pat, Owner of Acme and Member of Globex, signed in; returns a third-party app's id. */
function pickerFixture(): string
{
    $subject = app(Subjects::class)->create('pat@acme.test', 'Pat Doe', 'supersecret123');
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-picker'));
    $globex = app(Organizations::class)->create(new NewOrganization('Globex Corporation', 'globex-picker'));
    app(Memberships::class)->add($acme->id, $subject->id, MembershipRole::Owner);
    app(Memberships::class)->add($globex->id, $subject->id, MembershipRole::Member);

    $session = app(SessionManager::class)->start($subject->id, $acme->id, ['pwd']);
    signInAsMember($subject->id);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return app(ClientRegistry::class)->register(new NewClient(
        'Tax App',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid', 'profile'],
    ))->client->client_id;
}

function pickerUrl(string $clientId, string $prompt): string
{
    return '/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid profile',
        'state' => 'st',
        'code_challenge' => pkceChallenge(),
        'code_challenge_method' => 'S256',
        'prompt' => $prompt,
    ]);
}

it('draws the organization picker and continues to consent for the chosen one', function (): void {
    $clientId = pickerFixture();

    $page = visit(pickerUrl($clientId, 'select_organization'))
        ->assertSee('Choose an organization')
        ->assertSee('Acme')
        ->assertSee('Globex Corporation')
        ->assertSee('Suggested')
        ->assertSee('Cancel and return to Tax App')
        ->assertNoJavascriptErrors()
        ->screenshot(filename: 'oauth-organization-picker');

    $page->click('Globex Corporation')
        ->assertSee('Authorize Tax App')
        ->assertSee('Globex Corporation')
        ->assertNoJavascriptErrors()
        ->screenshot(filename: 'oauth-consent-with-organization');
});

it('draws the organization picker in the dark and on a phone', function (): void {
    $clientId = pickerFixture();

    visit(pickerUrl($clientId, 'select_organization'))
        ->inDarkMode()
        ->assertSee('Choose an organization')
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'oauth-organization-picker-dark');

    visit(pickerUrl($clientId, 'select_organization'))
        ->resize(375, 812)
        ->assertSee('Globex Corporation')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoJavascriptErrors()
        ->screenshot(filename: 'oauth-organization-picker-mobile');
});

it('draws the create step and continues to consent for the new organization', function (): void {
    $clientId = pickerFixture();

    $page = visit(pickerUrl($clientId, 'create_organization'))
        ->assertSee('Create an organization')
        ->assertSee('You will be its owner')
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'oauth-create-organization');

    $page->fill('name', 'Anna\'s Bakery')
        ->press('Create and continue')
        ->assertSee('Authorize Tax App')
        ->assertSee('Anna\'s Bakery')
        ->assertNoJavascriptErrors();
});

it('draws the create step in the dark and on a phone', function (): void {
    $clientId = pickerFixture();

    visit(pickerUrl($clientId, 'create_organization'))
        ->inDarkMode()
        ->assertSee('Create an organization')
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'oauth-create-organization-dark');

    visit(pickerUrl($clientId, 'create_organization'))
        ->resize(375, 812)
        ->assertSee('Create and continue')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoJavascriptErrors()
        ->screenshot(filename: 'oauth-create-organization-mobile');
});

it('tells somebody signing up from an app which app it is for', function (): void {
    $clientId = app(ClientRegistry::class)->register(new NewClient(
        'Tax App',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid', 'profile'],
    ))->client->client_id;

    visit(pickerUrl($clientId, 'create'))
        ->assertSee('Create your account')
        ->assertSee('Sign up for Tax App')
        ->assertSee('Team or company name')
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'signup-for-app');
});

it('draws the environment\'s sign-up switch and asks before opening it', function (): void {
    actAsEnvironmentAdminOfATenant();

    $page = visit('/admin/sign-in-rules')
        ->assertSee('Self-service sign-up')
        ->assertSee('by invitation only')
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'sign-in-rules-self-service-off');

    $page->click('[aria-label="Self-service sign-up"]')
        ->assertSee('Let people sign up to')
        ->screenshot(filename: 'sign-in-rules-self-service-confirm')
        ->click('Turn on sign-up')
        ->assertSee('Anyone can create an account')
        ->assertNoJavascriptErrors();

    visit('/admin/sign-in-rules')
        ->inDarkMode()
        ->assertSee('Anyone can create an account')
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'sign-in-rules-self-service-dark');

    $phone = visit('/admin/sign-in-rules')
        ->resize(375, 812)
        ->assertVisible('[aria-label="Self-service sign-up"]')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true);

    // Below the rules form on a phone: brought into view so the screenshot is of the switch.
    $phone->script('document.querySelector(\'[aria-label="Self-service sign-up"]\').scrollIntoView({block: "center"})');
    $phone->screenshot(fullPage: false, filename: 'sign-in-rules-self-service-mobile');
});
