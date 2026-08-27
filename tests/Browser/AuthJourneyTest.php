<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;

/**
 * SIGNING IN, ALL THE WAY THROUGH, IN A BROWSER.
 *
 * Every other test of this flow starts from a session that was created in PHP. That is the
 * right shape for testing what a signed-in person may do, and it means the DOOR itself —
 * the two-step identifier-first form, the redirect that lands you where you were going,
 * the sign-out that has to work from anywhere — is exercised by nothing that renders it.
 *
 * The failures this catches are the ones that live BETWEEN pages: a redirect chain that
 * loses its destination, a session cookie that does not survive the hop, a form that posts
 * to a route the client cannot follow. tests/Feature asserts each hop's status code; a
 * person experiences the chain, and a chain is only as good as its worst link.
 *
 * `signInThroughTheForm()` is deliberately not a helper that skips to the end. The typing
 * is the test.
 */
beforeEach(function (): void {
    installedDeployment();
});

/** A verified owner of one organization, and nothing else. @return string the email */
function anEnrolledOwner(string $email = 'journey@acme.test'): string
{
    platformRootEnvironment();

    app(PlatformRoot::class)->run(function () use ($email): void {
        $subject = app(Subjects::class)->create($email, 'Ada Lovelace', 'a-strong-unbreached-passphrase');
        app(Subjects::class)->markEmailVerified($subject->id, $email);

        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-journey'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    });

    return $email;
}

/**
 * THE WHOLE DOOR: email, password, console — and back out again.
 *
 * Identifier-first means two round trips, and the second one is the one that breaks: the
 * password step is rendered by the same route with different props, so a regression there
 * shows up as a page that looks right and posts nowhere.
 */
it('signs a person in through the form and back out again', function (): void {
    $email = anEnrolledOwner();

    $page = visit('/login');

    $page->assertSee('Sign in')
        ->fill('email', $email)
        ->press('Continue');

    // The second step, on the same URL. The email is carried and shown, so nobody has to
    // remember which address they just typed.
    $page->assertSee('Password')
        ->assertValue('input[type="email"]', $email);

    $page->fill('input[type="password"]', 'a-strong-unbreached-passphrase')
        ->click('button[type="submit"]');

    /*
     * TEXT FIRST, PATH SECOND, and the order is the whole trick. `assertPathIs` reads the
     * URL as it is right now — it does not wait for a navigation to finish — so asserting
     * it directly after a submit reads the page being left rather than the one being
     * reached. `assertSee` waits for the render, and by the time it passes the URL has
     * settled. Written the other way round this test fails on a working sign-in.
     */
    $page->assertSee('Welcome back')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();

    /*
     * AND OUT. Signing out is the one control that must work from anywhere — it is the
     * only way a person on a shared machine can end the session, and it posts rather than
     * links, so it is the kind of thing a routing change breaks silently.
     */
    $page->click('button.cbx-railitem');

    /*
     * A MENU ITEM, not a button — the account menu is a Radix dropdown and its entries are
     * `role="menuitem"` divs. Signing out POSTs from there, because a GET that ends a
     * session can be fired by any image tag on any page.
     */
    $page->click('[role="menuitem"]:has-text("Sign out")');

    $page->assertSee('Welcome back. Access your organization')
        ->assertPathIs('/login');

    // The console is genuinely closed behind them, not merely navigated away from: asked
    // for again by URL, it is the door that answers.
    $page->navigate('/dashboard');

    $page->assertSee('Welcome back. Access your organization')
        ->assertPathIs('/login');
})->group('a11y');

/**
 * A WRONG PASSWORD SAYS SO, ON THE PAGE, WITHOUT LOSING THE EMAIL.
 *
 * The failure mode being designed against is a form that clears itself on error: the
 * person retypes the address they already typed, gets it wrong under stress, and blames
 * the password. The server's refusal is held in tests/Feature; what is held here is that
 * the refusal is VISIBLE and the field is still filled.
 */
it('refuses a wrong password in words, and keeps the email', function (): void {
    $email = anEnrolledOwner('wrongpw@acme.test');

    $page = visit('/login');

    $page->fill('email', $email)
        ->press('Continue')
        ->fill('input[type="password"]', 'not-the-right-passphrase')
        ->click('button[type="submit"]');

    // Still at the door, told why, with the address intact.
    $page->assertSee('Those credentials do not match our records')
        ->assertPathIs('/login')
        ->assertValue('input[type="email"]', $email)
        ->assertNoJavaScriptErrors();
})->group('a11y');

/**
 * AN INTERRUPTED VISIT RESUMES WHERE IT WAS GOING.
 *
 * Somebody opens a link to a deep console page, is bounced to sign in, and must land on
 * the page they asked for — not on the dashboard. That intent is stashed by `guest()` and
 * survives a two-step form and a redirect chain, which is three places it can be dropped
 * and no request test walks all three.
 */
it('returns a person to the page they were trying to reach', function (): void {
    $email = anEnrolledOwner('intended@acme.test');

    // Asked for cold, signed out.
    $page = visit('/settings');

    $page->assertSee('Welcome back. Access your organization')
        ->assertPathIs('/login');

    $page->fill('email', $email)
        ->press('Continue')
        ->fill('input[type="password"]', 'a-strong-unbreached-passphrase')
        ->click('button[type="submit"]');

    $page->assertSee('Settings')
        ->assertPathIs('/settings')
        ->assertNoJavaScriptErrors();
})->group('a11y');
