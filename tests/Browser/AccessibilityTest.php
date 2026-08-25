<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/**
 * ACCESSIBILITY, IN A REAL BROWSER.
 *
 * The sweep this replaces ran axe over the SERVER's HTML inside jsdom, which was the right
 * tool for a server-rendered console and is the wrong one for a client-rendered page:
 * there is nothing in the response but a mount point, so it audits an empty document and
 * reports no violations — the shape of green the guard's own docblock warns about.
 *
 * It is also strictly better coverage, not merely equivalent. jsdom has no layout engine
 * and no cascade, so the old bridge had to disable `color-contrast` outright — the single
 * rule this design system's tokens are most carefully tuned for, and the one that has
 * actually regressed here before. A real browser computes it.
 *
 * The jsdom sweep stays for the pages still served by Volt, and shrinks as they port.
 */
beforeEach(function (): void {
    installedDeployment();
});

/**
 * A subject with a session the BROWSER will carry, not only this process.
 *
 * The page drives its own request, so the session id has to be in the cookie jar that
 * request uses — putting it in the session store alone signs nobody in.
 */
function signedInForAudit(): void
{
    $subject = app(Subjects::class)->create('a11y-browser@acme.test', 'Audit Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-a11y-browser'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);

    signInAsMember($subject->id);
    session([PlatformAuth::SESSION_KEY => $session->id]);
}

it('has no accessibility issues on the sign-in surfaces', function (string $path): void {
    if ($path === '__reset__') {
        app(Subjects::class)->create('reset-browser@acme.test', 'R', 'super-secret-1234');
        $path = '/reset-password/'.app(PasswordReset::class)->request('reset-browser@acme.test');
    }

    visit($path)
        ->assertNoAccessibilityIssues()
        // A page that threw during render is a page axe found nothing wrong with.
        ->assertNoJavaScriptErrors();
})->with([
    'forgot-password' => '/forgot-password',
    'reset-password' => '__reset__',
])->group('a11y');

/**
 * The hosted surface had no landmarks at all: its layout carried neither a <main> nor a
 * skip link, while every other layout in the repo carried both. That covers sign-in, MFA,
 * passkeys, consent and device approval — the pages a tenant's own users see, and the ones
 * this platform is judged on.
 *
 * Asserted on the RENDERED page rather than on the response body, because the skip link
 * comes from the root view and the landmark from React, and the promise is about the
 * document a person actually gets.
 */
it('gives every ported surface one main landmark and a skip link', function (string $path): void {
    visit($path)
        ->assertPresent('#main-content')
        ->assertPresent('a[href="#main-content"]')
        // EXACTLY ONE. Two `<main>` elements is not a landmark, it is an ambiguity, and
        // the skip link can then only reach one of them. Counted in the page rather than
        // through a selector assertion, which waits for the FIRST match and cannot
        // therefore report a second.
        ->assertScript('document.querySelectorAll("main").length', 1);
})->with([
    'forgot-password' => '/forgot-password',
])->group('a11y');

it('has no accessibility issues on the ported console pages', function (string $path): void {
    signedInForAudit();

    visit($path)
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();
})->with([
    'webhooks' => '/webhooks',
])->group('a11y');
