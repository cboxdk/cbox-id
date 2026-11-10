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
        ->click('header button[aria-label="Switch app"]')
        ->assertVisible('cbox-app-switcher .panel')
        ->assertSee('No apps have been published for you yet.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'app-switcher-mobile');
});

it('opens the personal picker from the signed-in header', function (): void {
    actingAsRole(MembershipRole::Owner);
    visit('/dashboard')
        ->click('header button[aria-label="Switch app"]')
        ->assertPathIs('/dashboard')
        ->assertSee('Switch app')
        ->assertSee('No apps have been published for you yet.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'app-switcher-empty');
});

it('opens an existing picker bookmark in the account header', function (): void {
    actingAsRole(MembershipRole::Owner);
    visit('/switch-app')
        ->assertPathIs('/account')
        ->assertVisible('cbox-app-switcher .panel')
        ->assertSee('No apps have been published for you yet.')
        ->assertNoJavaScriptErrors();
});
