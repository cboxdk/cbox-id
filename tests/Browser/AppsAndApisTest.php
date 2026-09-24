<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\Apis;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ApiScopeDefinition;
use Cbox\Id\OAuthServer\ValueObjects\NewApi;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;

/**
 * THE APP PAGE'S NEW TABS AND THE APIS AREA, DRAWN.
 *
 * The feature suite proves what each request does. It cannot see whether the scope picker
 * draws an API's scopes under its name, whether a refusal lands where the person is
 * looking, or whether a list of secrets fits on a phone — a control that is present in the
 * props and never drawn passes every request-level test there is.
 */
beforeEach(function (): void {
    installedDeployment();
});

/**
 * An owner of an organization in the platform root, with one app of their own, and a Tax
 * API the environment registered: `tax:read` for every organization, `tax:assess` kept.
 */
function anOwnerWithAnAppAndATaxApi(): Client
{
    platformRootEnvironment();

    [$owner, $client] = app(PlatformRoot::class)->run(function (): array {
        $owner = app(Subjects::class)->create('olivia@apps.test', 'Olivia Owner', 'a-strong-unbreached-passphrase');
        app(Subjects::class)->markEmailVerified($owner->id, 'olivia@apps.test');

        $org = app(Organizations::class)->create(new NewOrganization('Acme Apps', 'acme-apps-browser'));
        app(Memberships::class)->add($org->id, $owner->id, MembershipRole::Owner);

        app(Apis::class)->register(new NewApi(
            identifier: 'https://tax.example.com',
            name: 'Tax',
            scopes: [
                new ApiScopeDefinition('tax:read', 'Read returns'),
                new ApiScopeDefinition('tax:assess', 'Assess returns', tenantRequestable: false),
            ],
        ));

        $registered = app(ClientRegistry::class)->register(new NewClient(
            name: 'Acme Billing',
            type: ClientType::Confidential,
            redirectUris: ['https://billing.acme.test/auth/callback'],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: ['openid', 'profile', 'tax:read', 'reports.export'],
            organizationId: $org->id,
        ));

        // A rotation with an overlap, so the secrets list has a current secret and one
        // on its way out.
        app(ClientRegistry::class)->rotateSecret($registered->client, 86400);

        return [$owner, $registered->client];
    });

    signInAsMember($owner->id);

    return $client;
}

it('draws the scope picker with the API\'s scopes under its name, and the audience', function (): void {
    $client = anOwnerWithAnAppAndATaxApi();

    visit('/apps/'.$client->id.'/scopes')
        ->assertSee('Token audience')
        ->assertSee('https://tax.example.com')
        ->assertSee('tax:read')
        ->assertDontSee('tax:assess')
        ->assertSee('Typed scopes')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'c7-scopes-light');

    visit('/apps/'.$client->id.'/scopes')->inDarkMode()
        ->assertSee('Token audience')
        ->screenshot(filename: 'c7-scopes-dark');
})->group('a11y');

it('shows a refused scope as a sentence where the person is looking', function (): void {
    $client = anOwnerWithAnAppAndATaxApi();

    visit('/apps/'.$client->id.'/scopes')
        ->assertSee('Typed scopes')
        ->fill('customScopes', 'reports.export, tax:assess')
        ->click('button:has-text("Save scopes")')
        ->assertSee('Scopes not saved')
        ->assertSee('"tax:assess" belongs to the Tax API (https://tax.example.com), which keeps it for this environment\'s own apps.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'c7-scopes-refused');
})->group('a11y');

it('draws the secrets list and the rotation choices', function (): void {
    $client = anOwnerWithAnAppAndATaxApi();

    visit('/apps/'.$client->id.'/secrets')
        ->assertSee('Live secrets')
        ->assertSee('Current')
        ->assertSee('Replaced — still working')
        ->assertSee('After 24 hours')
        ->assertScript('Array.from(document.querySelectorAll("button")).filter(b => b.textContent.trim() === "Revoke").length', 2)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'c7-secrets-light');

    visit('/apps/'.$client->id.'/secrets')->inDarkMode()
        ->assertSee('Live secrets')
        ->screenshot(filename: 'c7-secrets-dark');
})->group('a11y');

it('draws the settings page', function (): void {
    $client = anOwnerWithAnAppAndATaxApi();

    visit('/apps/'.$client->id.'/settings')
        ->assertSee('Access token lifetime')
        ->assertSee('Token exchange')
        ->assertSee('Back-channel logout')
        ->assertSee('Key prefix')
        ->assertSee('My account › API keys')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'c7-settings-light');

    visit('/apps/'.$client->id.'/settings')->inDarkMode()
        ->assertSee('Key prefix')
        ->screenshot(filename: 'c7-settings-dark');
})->group('a11y');

it('draws every app tab at phone width without a sideways scroll', function (string $tab, string $heading): void {
    $client = anOwnerWithAnAppAndATaxApi();

    visit('/apps/'.$client->id.$tab)->resize(375, 812)
        ->assertSee($heading)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->screenshot(filename: 'c7-mobile'.str_replace('/', '-', $tab === '' ? '/overview' : $tab));
})->with([
    'overview' => ['', 'Connect it'],
    'scopes' => ['/scopes', 'Token audience'],
    'secrets' => ['/secrets', 'Live secrets'],
    'settings' => ['/settings', 'Key prefix'],
])->group('a11y');

/*
| The environment console
*/

function anEnvironmentAdminWithAnApi(): void
{
    actAsEnvironmentAdminOfATenant();

    $org = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-apis'));

    app(Apis::class)->register(new NewApi(
        identifier: 'https://tax.example.com',
        name: 'Tax',
        scopes: [
            new ApiScopeDefinition('tax:read', 'Read returns'),
            new ApiScopeDefinition('tax:assess', 'Assess returns', tenantRequestable: false),
        ],
    ));
    app(Apis::class)->register(new NewApi(
        identifier: 'https://books.globex.example',
        name: 'Globex books',
        organizationId: $org->id,
        scopes: [new ApiScopeDefinition('books:read')],
    ));
}

it('draws the APIs list, an API and the new-API form on the environment console', function (): void {
    anEnvironmentAdminWithAnApi();
    $tax = app(Apis::class)->identifiedBy('https://tax.example.com');

    visit('/admin/apis')
        ->assertSee('Globex books')
        ->assertSee('https://tax.example.com')
        ->assertSee('This environment')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'c7-apis-index');

    visit('/admin/apis')->inDarkMode()->assertSee('Globex books')->screenshot(filename: 'c7-apis-index-dark');

    visit('/admin/apis/'.$tax?->id)
        ->assertSee('Your apps only')
        ->assertSee('Organizations\' apps may request')
        ->assertSee('Add a scope')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'c7-apis-show');

    visit('/admin/apis/'.$tax?->id)->resize(375, 812)
        ->assertSee('Add a scope')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->screenshot(filename: 'c7-apis-show-mobile');

    visit('/admin/apis/new')
        ->assertSee('Identifier')
        ->assertSee('To register an API for one organization')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'c7-apis-create');
})->group('a11y');

it('draws the copy dialog on an environment app', function (): void {
    actAsEnvironmentAdminOfATenant();

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Storefront',
        type: ClientType::Confidential,
        redirectUris: ['https://shop.acme.example/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ))->client;

    visit('/admin/apps/'.$client->id)
        ->assertSee('Download blueprint')
        ->click('button:has-text("Copy to another environment")')
        ->assertSee('Registers this app again in another environment of this project')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'c7-copy-dialog');
})->group('a11y');
