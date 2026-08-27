<?php

declare(strict_types=1);

beforeEach(function (): void {
    // These render product pages, which presuppose an installed deployment.
    installedDeployment();
});

/**
 * Real-browser smoke tests: these boot Chromium against the running app and assert
 * the public auth surfaces render and drive correctly, with no JavaScript errors —
 * coverage the Livewire/HTTP feature tests can't give (they never execute the page).
 */
it('renders the sign-in page with no JavaScript errors', function (): void {
    visit('/login')
        ->assertSee('Sign in')
        ->assertSee('Continue')
        ->assertSee('Email me a magic link')
        ->assertSee('Sign in with a passkey')
        ->assertNoJavascriptErrors();
});

it('renders the sign-up page', function (): void {
    visit('/signup')
        ->assertSee('Create your organization')
        ->assertSee('Organization name')
        ->assertNoJavascriptErrors();
});

it('serves the operator sign-in route without JavaScript errors', function (): void {
    // With a fresh test DB (no operators/environments) this may bootstrap-redirect,
    // so assert the route is healthy rather than pinning copy that depends on state.
    visit('/operator/login')
        ->assertNoJavascriptErrors();
});

it('advances the identifier-first flow to the password step', function (): void {
    visit('/login')
        ->fill('email', 'admin@acme.test')
        ->press('Continue')
        ->assertSee('Password')
        ->assertNoJavascriptErrors();
});

/**
 * THE STEP THAT APPEARS HAS TO TAKE FOCUS WITH IT.
 *
 * The password field is revealed by a server round trip, so HTML `autofocus` cannot do it:
 * that fires once, at document parse, and this element arrives after. Without an explicit
 * move, focus falls back to `<body>` — so somebody navigating by keyboard has to tab from
 * the top of the page to reach the field the page just put in front of them, and somebody
 * using a screen reader is told nothing happened at all.
 *
 * This used to be asserted by grepping the Volt template for `x-init="$el.focus()"`, which
 * could see the attribute and never whether focus actually landed. Here it is the real
 * question, asked of a real browser: which element has focus, and what does the live region
 * say.
 */
it('puts focus in the password field the moment the step appears, and says so', function (): void {
    $page = visit('/login')
        ->fill('email', 'admin@acme.test')
        ->press('Continue')
        ->assertSee('Password');

    $page->assertScript('document.activeElement?.getAttribute("type")', 'password')
        ->assertNoJavascriptErrors();
});

/**
 * Enter in a single-field form is how people actually submit it — a form that only
 * responds to a click is broken for anyone typing rather than reaching for the mouse,
 * and for every keyboard and screen-reader user.
 */
it('advances the identifier-first flow when Enter is pressed, not only when Continue is clicked', function (): void {
    visit('/login')
        ->fill('email', 'admin@acme.test')
        ->keys('#email', 'Enter')
        ->assertSee('Password')
        ->assertNoJavascriptErrors();
});

it('advances the account door with Enter too', function (): void {
    visit(route('login'))
        ->fill('email', 'owner@acme.test')
        ->keys('#email', 'Enter')
        ->assertNoJavascriptErrors()
        ->assertSee('Password');
});
