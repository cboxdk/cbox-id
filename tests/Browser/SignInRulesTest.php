<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\PlatformRoot;

/**
 * THE QUESTION THAT USED TO BE A ROUND TRIP.
 *
 * Under Volt, "this will sign people out" was server state: `save()` refused to write and
 * set a flag, and a feature test could assert the flag. There is no round trip to hang it
 * on now — the form asks in the browser before it submits — so the only place the promise
 * can be checked is a browser.
 *
 * Which is the point rather than a concession. What matters was never that a property
 * flipped; it is that a person about to end every password session in their company is
 * TOLD SO, on screen, before it happens. The feature suite holds the fact the page asks on
 * (`passwordsCurrentlyWork`); this holds the asking.
 */
beforeEach(function (): void {
    installedDeployment();
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
});

/** An owner of an organization that owns a product, signed in, on the organization plane. */
function anOwnerOfSignInRules(): string
{
    platformRootEnvironment();

    return app(PlatformRoot::class)->run(function (): string {
        $subject = app(Subjects::class)->create('rules-browser@acme.test', 'Owner', 'a-strong-unbreached-passphrase');
        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-rules-browser'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
        app(Projects::class)->createForOrganization($org->id, 'Acme');

        signInAsMember($subject->id);

        return $org->id;
    });
}

it('warns before requiring SSO, and writes nothing until the warning is answered', function (): void {
    $organizationId = anOwnerOfSignInRules();

    // A member holding a live password session — the person the warning is about. In the
    // PLATFORM ROOT, where the console reads and writes them.
    $session = app(PlatformRoot::class)->run(function () use ($organizationId) {
        $member = app(Subjects::class)->create('rules-member@acme.test', 'Member', 'a-strong-unbreached-passphrase');
        app(Memberships::class)->add($organizationId, $member->id, MembershipRole::Member);

        return app(SessionManager::class)->start($member->id, $organizationId, ['pwd']);
    });

    $page = visit('/sign-in-rules');

    $page->assertSee('Sign-in rules')
        ->assertDontSee('This will sign people out of');

    // Choose the mandate and submit. The dialog interrupts.
    $page->click('[aria-label="Single sign-on"]')
        // The option is a Radix listbox row rather than a button, so it is named by its
        // role: clicking on text alone looks only at the controls a person can tab to.
        ->click('[role="option"]:has-text("Require SSO")')
        ->click('Save rules')
        ->assertSee('This will sign people out of Acme')
        ->assertSee('including yours');

    // NOTHING IS WRITTEN while the question is on screen. A mandate that took effect on
    // the way to asking would make the question decoration.
    expect(app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->overrideFor($organizationId),
    ))->toBeNull()
        ->and(app(SessionManager::class)->active($session->id))->not->toBeNull();

    // And declining leaves it that way.
    $page->click('Keep passwords working')
        ->assertDontSee('This will sign people out of')
        ->assertNoJavaScriptErrors();

    expect(app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->overrideFor($organizationId),
    ))->toBeNull();
})->group('a11y');

it('applies the mandate when the warning is accepted', function (): void {
    $organizationId = anOwnerOfSignInRules();

    visit('/sign-in-rules')
        ->click('[aria-label="Single sign-on"]')
        // The option is a Radix listbox row rather than a button, so it is named by its
        // role: clicking on text alone looks only at the controls a person can tab to.
        ->click('[role="option"]:has-text("Require SSO")')
        ->click('Save rules')
        ->click('Require SSO and sign everyone out')
        ->assertNoJavaScriptErrors();

    expect(app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->resolve($organizationId)->sso,
    ))->toBe(SsoEnforcement::Required);
})->group('a11y');
