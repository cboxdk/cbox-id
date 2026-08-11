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
        ->on()->mobile()
        // The thumb-zone bar the shared component pins to the foot of the viewport. If the
        // shell ever loses it — or reverts to a hamburger stranded in a corner — this is
        // what notices.
        ->assertVisible('[data-cbox-mobile-nav]')
        ->assertNoJavascriptErrors();
});

it('keeps the console usable at a phone width without a horizontal scrollbar', function (): void {
    signedInOnAPhone();

    $page = visit('/dashboard')->on()->mobile();

    // A page that scrolls sideways on a phone is one where somebody set a fixed width or
    // let a table escape its container — the most common way a "responsive" console stops
    // being one, and invisible to every assertion about content.
    $overflows = $page->script('document.documentElement.scrollWidth > document.documentElement.clientWidth + 1');

    expect($overflows)->toBeFalse();
})->skip(fn (): bool => ! method_exists(Webpage::class, 'script'), 'the browser plugin exposes no script bridge');
