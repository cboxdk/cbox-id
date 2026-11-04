<?php

declare(strict_types=1);

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
    visit(route('environment.app-catalog'))
        ->click('button[aria-label="Switch app"]')
        ->assertSee('Your apps')
        ->assertSee('No apps have been published for you yet.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'app-switcher-empty');
});
