<?php

declare(strict_types=1);
use Cbox\Id\Organization\Enums\MembershipRole;

beforeEach(fn () => installedDeployment());

it('shows the catalog editor and keyboard-accessible app selector in both themes', function (): void {
    crudSetup();
    visit(route('environment.app-catalog.create'))
        ->assertSee('Add app')
        ->assertSee('Launch URL')
        ->assertSee('Selected OAuth integrations')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'app-catalog-light');
    visit(route('environment.app-catalog.create'))->inDarkMode()
        ->assertSee('Who can see this app?')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'app-catalog-dark');

});

it('opens the same app selector directly in the mobile header', function (): void {
    crudSetup();
    visit(route('environment.app-catalog'))
        ->resize(375, 812)
        ->click('header.cbx-header button[aria-label="Open navigation"]')
        ->click('.cbx-sheet button:has-text("Switch app")')
        ->assertVisible('.cbx-menu-apps')
        ->assertSee('No apps available')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'app-switcher-mobile');
});

it('opens the personal picker from the signed-in header', function (): void {
    actingAsRole(MembershipRole::Owner);
    visit('/dashboard')
        ->click('header button[aria-label="Switch app"]')
        ->assertPathIs('/dashboard')
        ->assertVisible('.cbx-menu-apps')
        ->assertSee('No apps available')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'app-switcher-empty');
});

it('lands an old picker bookmark on the account page, with the picker in its header', function (): void {
    actingAsRole(MembershipRole::Owner);
    visit('/switch-app')
        ->assertPathIs('/settings')
        ->click('header button[aria-label="Switch app"]')
        ->assertVisible('.cbx-menu-apps')
        ->assertSee('No apps available')
        ->assertNoJavaScriptErrors();
});
