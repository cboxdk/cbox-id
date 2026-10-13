<?php

declare(strict_types=1);

/**
 * SIGNING OUT OF AN ENVIRONMENT'S CONSOLE, IN A BROWSER.
 *
 * The owner's report was a browser one: Log out on `acme.cboxid.com/admin`, open `/admin`
 * again, and the console was back. tests/Feature/SignOutEverywhereTest.php holds the
 * server's half across both hosts, with a cookie jar per host. What only a browser shows is
 * the landing — a page that says so, rather than a door that hands the person straight
 * back in — and that the console's own menu reaches it.
 *
 * ONE HOST, NOT TWO. The browser harness serves the app on one local origin, so the
 * account host (`cboxid.com` in production) cannot be served beside the environment's. The
 * account host is pointed at a `.invalid` name, which never resolves, so nothing in this
 * test can reach a real host, and the cross-host half stays in the feature test.
 */
beforeEach(function (): void {
    installedDeployment();
});

it('lands an environment console\'s sign-out on a page that says so and enters nothing', function (): void {
    actAsEnvironmentAdminOfATenant();
    config()->set('cbox-id.tenancy.account_host', 'account.cbox-id.invalid');

    $page = visit('/admin');

    $page->click('button.cbx-avatar-btn');
    $page->click('[role="menuitem"]:has-text("Sign out")');

    $page->assertSee('You\'re signed out')
        ->assertPathIs('/signed-out')
        // Sign in goes back through the console's own gate, on this host — when the person
        // means it, not by itself.
        ->assertScript('new URL(document.querySelector("a.btn-primary").href).pathname', '/admin')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();

    $page->resize(375, 812)
        ->assertSee('You\'re signed out')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);
})->group('a11y');
