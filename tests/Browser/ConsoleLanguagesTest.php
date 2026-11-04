<?php

declare(strict_types=1);

beforeEach(function (): void {
    installedDeployment();
});

it('switches the environment console to Danish and keeps the choice on navigation and reload', function (): void {
    actAsEnvironmentAdminOfATenant();

    $page = visit('/admin');
    $page->click('[data-testid="console-language-picker"]')
        ->click('[role="menuitemradio"]:has-text("Dansk")')
        ->assertSee('Brugere og organisationer')
        ->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.lang'))->toBe('da');
    $page->navigate('/admin/users')->assertSee('Ny bruger')->assertNoJavaScriptErrors();
    $page->script('window.location.reload()');
    $page->assertSee('Ny bruger')->assertNoJavaScriptErrors();
    $page->screenshot(false, 'console-languages-danish-users');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->click('[data-testid="console-language-picker"]')
        ->click('[role="menuitemradio"]:has-text("English")')
        ->assertSee('New user')
        ->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.lang'))->toBe('en');
});

it('renders platform administration in Danish at a phone width', function (): void {
    actAsOperator();
    $page = visit('/admin/platform/workspaces')->on()->mobile()->assertSee('Workspaces');
    $page->click('[data-testid="console-language-picker"]')
        ->click('[role="menuitemradio"]:has-text("Dansk")');
    $page->assertSee('Arbejdsområder')->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.lang'))->toBe('da')
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(false, 'console-languages-danish-platform-mobile');
});

it('switches through every additional language from the mobile topbar', function (): void {
    actAsEnvironmentAdminOfATenant();
    $page = visit('/admin/users')->on()->mobile()->assertSee('Users');
    foreach ([
        ['de', 'Deutsch', 'Benutzer'],
        ['sv', 'Svenska', 'Användare'],
        ['nb', 'Norsk bokmål', 'Brukere'],
        ['fi', 'Suomi', 'Käyttäjät'],
        ['fr', 'Français', 'Utilisateurs'],
        ['es', 'Español', 'Usuarios'],
        ['it', 'Italiano', 'Utenti'],
        ['nl', 'Nederlands', 'Gebruikers'],
    ] as [$locale, $name, $title]) {
        $page->click('[data-testid="console-language-picker"]')
            ->click('[role="menuitemradio"]:has-text("'.$name.'")')
            ->assertSee($title)
            ->assertNoJavaScriptErrors();
        expect($page->script('document.documentElement.lang'))->toBe($locale)
            ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
        if (in_array($locale, ['de', 'fi', 'nl'], true)) {
            $page->screenshot(false, 'console-language-'.$locale.'-mobile');
        }
    }
    $page->script('window.location.reload()');
    $page->assertSee('Gebruikers')->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.lang'))->toBe('nl');
});
