<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * THE API KEY PAGES, DRAWN.
 *
 * The feature suite proves what the server sends and what it refuses. It cannot see
 * whether the permission checkboxes are drawn, whether the key appears with a copy button,
 * whether "Back to …" is on the page, or whether any of it fits on a phone — a control that
 * is never drawn passes every request-level test there is. So each page is driven here in
 * light, in dark and at 375px.
 */
beforeEach(function (): void {
    installedDeployment();
});

it('creates a key on the hosted page from an SDK deep link and shows it once', function (): void {
    $fixture = appKeyFixture();
    signInKeyHolder($fixture['ada'], $fixture['org']);

    $page = visit('/account/api-keys?client_id='.$fixture['clientId'].'&return_to='.urlencode('https://tax.example/settings/api'));

    $page->assertSee('Back to Acme Tax')
        ->assertSee('See your tax returns')
        ->assertSee('File a tax return')
        // Ada does not hold it, so it is not drawn at all.
        ->assertDontSee('settings:manage')
        ->fill('name', 'Accounting sync')
        ->click('label:has-text("returns:read")')
        ->screenshot(filename: 'api-keys-new-key');

    $page->click('button:has-text("Create key")')
        ->assertSee('Copy your key now')
        ->assertSee('Copied it?')
        ->assertSee('Accounting sync')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'api-keys-created');

    $key = app(CustomerApiKeys::class)->forUser($fixture['org']->id, $fixture['ada'])->sole();

    expect($key->permissions)->toBe(['returns:read']);
})->group('a11y');

it('draws the key page in the dark', function (): void {
    $fixture = appKeyFixture();
    mintAppKey($fixture, $fixture['ada'], ['returns:read']);
    signInKeyHolder($fixture['ada'], $fixture['org']);

    visit('/account/api-keys')
        ->inDarkMode()
        ->assertSee('Your keys')
        ->assertSee('returns:read')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'api-keys-dark');
})->group('a11y');

it('draws the key page at phone width without a sideways scroll', function (): void {
    $fixture = appKeyFixture();
    mintAppKey($fixture, $fixture['ada'], ['returns:read', 'returns:file']);
    signInKeyHolder($fixture['ada'], $fixture['org']);

    visit('/account/api-keys')
        ->resize(375, 812)
        ->assertSee('Your keys')
        ->assertSee('Create key')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'api-keys-mobile');
})->group('a11y');

it('lets an administrator see every member\'s key and revoke one, in light, dark and on a phone', function (): void {
    $fixture = appKeyFixture();
    mintAppKey($fixture, $fixture['ada'], ['returns:read']);
    mintAppKey($fixture, $fixture['bob'], name: 'Bob\'s script');

    $admin = app(Subjects::class)->create('admin@acme-keys.test', 'Olivia Admin', 'supersecret123');
    app(Memberships::class)->add($fixture['org']->id, $admin->id, MembershipRole::Admin);
    signInKeyHolder($admin->id, $fixture['org']);

    $page = visit('/directory/api-keys');

    $page->assertSee('Member API keys')
        ->assertSee('Ada Lovelace')
        ->assertSee('Bob')
        ->assertSee('No permissions')
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'member-api-keys');

    $page->click('[aria-label="Revoke Bob\'s script"]')
        ->fill('.cbx-dialog input.input', 'Bob\'s script')
        ->click('.cbx-dialog button:has-text("Revoke")')
        ->assertSee('API key revoked.')
        ->assertSee('Revoked')
        ->assertNoJavaScriptErrors();

    visit('/directory/api-keys')
        ->inDarkMode()
        ->assertSee('Ada Lovelace')
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'member-api-keys-dark');

    visit('/directory/api-keys')
        ->resize(375, 812)
        ->assertSee('Ada Lovelace')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->screenshot(filename: 'member-api-keys-mobile');
})->group('a11y');

it('draws an organization\'s keys on the environment console\'s organization page, light, dark and on a phone', function (): void {
    actAsEnvironmentAdminOfATenant();

    $fixture = appKeyFixture('tenant-keys-browser');
    mintAppKey($fixture, $fixture['ada'], ['returns:read', 'returns:file'], name: 'Accounting sync');

    // The panel sits below the roster and the invite form; bring it into the frame.
    $toPanel = 'Array.from(document.querySelectorAll("h2")).find((h) => h.textContent === "API keys")?.scrollIntoView()';

    $light = visit('/admin/organizations/'.$fixture['org']->id)
        ->assertSee('API keys')
        ->assertSee('Accounting sync')
        ->assertSee('Ada Lovelace')
        ->assertNoJavaScriptErrors();
    $light->script($toPanel);
    $light->screenshot(filename: 'environment-organization-api-keys');

    $dark = visit('/admin/organizations/'.$fixture['org']->id)
        ->inDarkMode()
        ->assertSee('Accounting sync');
    $dark->script($toPanel);
    $dark->screenshot(filename: 'environment-organization-api-keys-dark');

    $phone = visit('/admin/organizations/'.$fixture['org']->id)
        ->resize(375, 812)
        ->assertSee('Accounting sync')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);
    $phone->script($toPanel);
    $phone->screenshot(filename: 'environment-organization-api-keys-mobile');
})->group('a11y');
