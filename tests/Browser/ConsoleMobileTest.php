<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Pest\Browser\Api\Webpage;

/**
 * THE CONSOLE, ON A PHONE, IN A REAL BROWSER.
 *
 * Every other test in this suite asserts on markup, and markup cannot answer the question
 * that matters here: is the control DRAWN. A rail that is `hidden lg:flex` is present in
 * the HTML and invisible at 375px, and a suite that greps for it passes while the page is
 * unusable — which has happened here before, twice.
 *
 * So these boot Chromium at a phone viewport and ask the browser whether the navigation is
 * visible. `assertVisible` resolves computed styles; `assertSee` would not.
 *
 * `resize(375, 812)` RATHER THAN `->on()->mobile()`, everywhere below, and the difference
 * is not cosmetic. The device preset also emulates TOUCH, and two things stop working
 * under it:
 *
 *  - A CLICK NEVER COMPLETES. The harness dispatches `pointerdown` and `touchstart` and no
 *    `touchend`, so no `click` is ever synthesised and nothing on the page can be pressed.
 *    Silently: the press reports success and every assertion after it fails describing the
 *    control it was trying to reach rather than the tap that never landed.
 *  - `script()` RETURNS FROM AN UNRENDERED DOCUMENT — `document.body` is the mount point
 *    and the props blob, and `querySelectorAll('button')` is empty. A measurement of
 *    nothing comes back reassuring, which is how the overflow check below spent its life
 *    proving that a page with no content in it fits on a phone.
 *
 * A plain narrow viewport is the same breakpoint and the same layout, and it behaves.
 * `tests/Browser/AccessibilityTest.php` still uses the device preset, correctly: it only
 * looks at pages — `assertSee`, `assertScript` and axe all wait for the render, and all
 * three were checked against a deliberately overflowing page before this was written.
 */
beforeEach(function (): void {
    installedDeployment();
});

function signedInOnAPhone(): void
{
    $subject = app(Subjects::class)->create('phone@acme.test', 'Phone Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-phone'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);

    signInAsMember($subject->id);

    // The browser drives its own request, so the session has to be in the cookie jar the
    // page will use rather than only in this process's session store.
    session([PlatformAuth::SESSION_KEY => $session->id]);
}

it('draws navigation on a phone, not just in the markup', function (): void {
    signedInOnAPhone();

    visit('/dashboard')
        ->resize(375, 812)
        // The thumb-zone bar the shared component pins to the foot of the viewport. If the
        // shell ever loses it — or reverts to a hamburger stranded in a corner — this is
        // what notices.
        ->assertVisible('[data-cbox-mobile-nav]')
        ->assertNoJavascriptErrors();
});

/**
 * …AND THE BAR HAS TO OPEN SOMETHING.
 *
 * The test above asserts the bar is drawn, which is where it stopped — and on a phone that
 * drawer is the ONLY navigation there is: the rail and the sub-nav are both withheld below
 * `sm`. A Menu button that opened nothing would leave a signed-in person on whatever page
 * they landed on with no way to any other, and every assertion in this file would still
 * pass, because a bar that draws and a bar that works are different facts.
 *
 * Found by opening it by hand, which is the only reason this exists.
 */
it('opens a drawer that can actually reach the rest of the console', function (): void {
    signedInOnAPhone();

    $page = visit('/dashboard')->resize(375, 812);

    /*
     * THE PAGE FIRST, and with an assertion that can FAIL on an empty document.
     *
     * Nothing here is server-rendered — the console is one mount point — so the bar exists
     * only once React has run. An `assertDontSee` in this position passes on a blank page,
     * and the click after it then goes into a document with nothing in it: no error, no
     * drawer, and every assertion below failing for a reason that has nothing to do with
     * the drawer.
     */
    $page->assertVisible('[data-cbox-mobile-nav]');

    /*
     * BY THE SELECTOR, not by a label. `press()` matches on VISIBLE TEXT, and this
     * trigger's accessible name is its `aria-label` — the text says "Menu" and the label
     * says "Open menu", so `press('Menu')` resolved to something else and pressed it
     * silently while `press('Open menu')` timed out finding nothing. The class belongs to
     * the shell and moves with it.
     */
    $page->click('.cbx-mobilebar-btn');

    $page->assertVisible('.cbx-sheet');

    /*
     * Signing out and the theme toggle live in the account menu on a desktop. At this
     * width the drawer is the only place either of them appears, which makes them the
     * proof that this is the SHEET and not the page behind it — a nav label would not be,
     * since which pages a person's nav holds depends on their role.
     */
    $page->assertSee('Sign out')
        ->assertSee('Toggle theme')
        // …and the navigation itself, grouped by AREA the way the rail is. A flat list of
        // every page is the failure mode this replaces.
        ->assertSee('People')
        ->assertSee('Members')
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('keeps the console usable at a phone width without a horizontal scrollbar', function (): void {
    signedInOnAPhone();

    $page = visit('/dashboard')->resize(375, 812);

    /*
     * RENDERED FIRST, and asserted rather than assumed. `script()` measured an EMPTY
     * document under the device preset — `document.body` was the mount point and the props
     * blob, nothing else — so `scrollWidth > clientWidth` was false because there was no
     * content, and this test reported a console that fits at 375px without ever having
     * looked at one.
     */
    $page->assertVisible('[data-cbox-mobile-nav]');

    expect($page->script('document.querySelectorAll("button").length'))
        ->toBeGreaterThan(0, 'the page under measurement had not rendered');

    // A page that scrolls sideways on a phone is one where somebody set a fixed width or
    // let a table escape its container — the most common way a "responsive" console stops
    // being one, and invisible to every assertion about content.
    $overflows = $page->script('document.documentElement.scrollWidth > document.documentElement.clientWidth + 1');

    expect($overflows)->toBeFalse();
})->skip(fn (): bool => ! method_exists(Webpage::class, 'script'), 'the browser plugin exposes no script bridge');
