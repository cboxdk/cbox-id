<?php

declare(strict_types=1);
use Cbox\Id\Organization\Enums\MembershipRole;

beforeEach(fn () => installedDeployment());

it('shows the catalog editor and keyboard-accessible app selector in both themes', function (): void {
    crudSetup();
    visit(route('environment.app-catalog.create'))
        ->assertSee('Add app')
        ->assertSee('Launch URL')
        ->assertSee('Selected clients')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'app-catalog-light');
    visit(route('environment.app-catalog.create'))->inDarkMode()
        ->assertSee('Who can see this app?')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'app-catalog-dark');

});

it('opens the same app selector from the mobile navigation sheet', function (): void {
    crudSetup();
    visit(route('environment.app-catalog'))
        ->resize(375, 812)
        ->click('.cbx-mobilebar-btn')
        ->assertVisible('.cbx-sheet')
        ->click('.cbx-sheet button[aria-label="Switch app"]')
        ->assertSee('Your apps')
        ->assertSee('No apps have been published for you yet.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'app-switcher-mobile');
});

it('opens the personal picker from the signed-in header', function (): void {
    actingAsRole(MembershipRole::Owner);
    visit('/dashboard')
        ->click('a[aria-label="Switch app"]')
        ->assertSee('Switch app')
        ->assertSee('No apps have been published for you yet.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'app-switcher-empty');
});
