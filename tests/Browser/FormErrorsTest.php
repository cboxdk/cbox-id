<?php

declare(strict_types=1);

use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\PlatformRoot;

/**
 * THE STATE A FORM SPENDS MOST OF ITS LIFE IN.
 *
 * A validation failure is not an edge case — it is what a form does whenever somebody is
 * in a hurry, and it is the moment they most need the page to be clear. tests/Feature
 * proves the SERVER refuses: a 422, a message under the right key. What no request test
 * can see is whether that message reaches a human — whether it is drawn at all, whether it
 * lands on the field it is about, whether the value they typed survives so they can fix it
 * rather than retype it.
 *
 * That is three separate ways for a correct 422 to be a broken page, and the suite could
 * not see any of them.
 */
beforeEach(function (): void {
    installedDeployment();
});

/** An owner who may register webhook endpoints, with the step-up window already open. */
function anOwnerRegistering(): void
{
    platformRootEnvironment();

    $subject = app(PlatformRoot::class)->run(function () {
        $subject = app(Subjects::class)->create('forms@acme.test', 'Owner', 'a-strong-unbreached-passphrase');
        app(Subjects::class)->markEmailVerified($subject->id, 'forms@acme.test');

        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-forms'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
        app(Projects::class)->createForOrganization($org->id, 'Acme');

        return $subject;
    });

    signInAsMember($subject->id);
    app(Sudo::class)->confirm();
}

/**
 * A REFUSED SUBMISSION SAYS SO, ON THE FIELD, AND KEEPS WHAT WAS TYPED.
 *
 * All three halves matter and they fail independently. A message rendered nowhere is a
 * form that appears to do nothing when pressed. A message rendered at the top of the page
 * is one a person on a phone never scrolls to. And a form that clears itself is one that
 * punishes the mistake it just reported.
 */
it('draws a server validation error on the field it belongs to', function (): void {
    anOwnerRegistering();

    $page = visit('/webhooks/new');

    $page->assertSee('New webhook');

    /*
     * A URL THE BROWSER ACCEPTS AND THE SERVER DOES NOT — and finding one is the point.
     *
     * The field is `type="url"`, so Chrome refuses a malformed value itself with its own
     * bubble ("Please enter a URL") and never submits: a first draft used `not-a-url` and
     * was testing the browser rather than this application. `max:500` is a rule only the
     * server knows, so this is a well-formed URL that is simply too long — which is the
     * shape of every server-only rule (uniqueness, ownership, entitlement) and therefore
     * the shape worth proving the page can display.
     */
    $long = 'https://valid.example.test/'.str_repeat('x', 520);

    $page->fill('input[type="url"]', $long)
        ->click('button[type="submit"]');

    /*
     * THE MESSAGE IS DRAWN. `field-error` is the design system's one place for this, and
     * `Field` wires it to the control with `aria-describedby` — so asserting the class is
     * asserting that the message went through the component that makes it announceable,
     * rather than being printed loose somewhere on the page.
     */
    $page->assertPresent('.field-error')
        // The server's own words for the rule it enforced.
        ->assertSee('must not be greater than 500')
        ->assertNoJavaScriptErrors();

    // AND THE VALUE SURVIVED. Retyping a URL to fix a scheme is the most avoidable
    // frustration a form can offer.
    $page->assertValue('input[type="url"]', $long);

    // Still on the form, not bounced to the list.
    $page->assertPathIs('/webhooks/new');
})->group('a11y');

/**
 * A SECOND FIELD'S ERROR IS ITS OWN.
 *
 * The event-type grid is a fieldset, and its error is rendered by hand rather than by
 * `Field` — a `role="alert"` paragraph that is `hidden` until there is something to say.
 * `hidden` is exactly the attribute that goes wrong: an element that is present and
 * permanently hidden reads as "no error" to everybody, and passes any test that only
 * checks the text is in the DOM.
 */
it('reveals the fieldset error rather than leaving it hidden', function (): void {
    anOwnerRegistering();

    $page = visit('/webhooks/new');

    // A VALID url, so the only thing wrong is the empty event list — otherwise this would
    // pass on the url error and prove nothing about the fieldset.
    $page->fill('input[type="url"]', 'https://valid.example.test/hook')
        ->click('button[type="submit"]');

    /*
     * VISIBLE, not merely present. `assertSee` resolves visibility, so a `hidden`
     * attribute that never lifts fails here — which is the whole reason this test exists
     * rather than a props assertion.
     */
    $page->assertVisible('[role="alert"]:not([hidden])')
        ->assertNoJavaScriptErrors();

    $page->assertPathIs('/webhooks/new');
})->group('a11y');

/**
 * AN EMPTY LIST SAYS WHAT TO DO NEXT.
 *
 * Every list in this console starts empty, and the empty state is the first thing a new
 * customer sees on that page — the one screen guaranteed to be rendered for everybody.
 * A props test sees `items: []` and can say nothing about whether the page then explains
 * itself or just draws a table head over nothing.
 */
it('explains an empty list instead of drawing an empty table', function (): void {
    anOwnerRegistering();

    $page = visit('/webhooks');

    $page->assertSee('Webhooks')
        // The empty state's own copy, and the way out of it.
        ->assertSee('Nothing is being notified yet')
        ->assertPresent('a:has-text("Add endpoint"), button:has-text("Add endpoint")')
        ->assertNoJavaScriptErrors();
})->group('a11y');
