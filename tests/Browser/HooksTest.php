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
 * CLEARING A LIVE CREDENTIAL OFF THE SCREEN.
 *
 * Under Volt, dismissing the revealed signing secret was a server round trip, because the
 * banner's visibility was server state — so a feature test could call the action and
 * assert the markup changed. There is nothing on the server to assert now, and that is the
 * point: by the time anybody presses Dismiss the secret is already gone from it. Making
 * the banner go IS the whole job, and the only place that can be checked is a browser.
 *
 * It is worth checking. This is plaintext on screen for a credential with no rotation, and
 * the person pressing the button is usually somebody who has just copied it — or who is
 * sharing a screen and has realised what is on it.
 */
beforeEach(function (): void {
    installedDeployment();
    config(['cbox-id.external_actions.verify_url' => false]);
});

/** An owner of an organization that owns a product, signed in, with sudo already fresh. */
function anOwnerRegisteringHooks(): void
{
    platformRootEnvironment();

    $subject = app(PlatformRoot::class)->run(function () {
        $subject = app(Subjects::class)->create('hooks-browser@acme.test', 'Owner', 'a-strong-unbreached-passphrase');
        app(Subjects::class)->markEmailVerified($subject->id, 'hooks-browser@acme.test');

        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-hooks-browser'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
        app(Projects::class)->createForOrganization($org->id, 'Acme');

        return $subject;
    });

    signInAsMember($subject->id);

    // The step-up window, opened so the registration form is reachable. Registering an
    // inline hook demands a fresh password — see ConsoleStepUpTest, which is where that
    // property is held; here it would only be a detour.
    app(Sudo::class)->confirm();
}

it('reveals the signing secret once and lets the reader clear it off the screen', function (): void {
    anOwnerRegisteringHooks();

    $page = visit('/hooks/new');

    $page->assertSee('New inline hook')
        ->fill('url', 'https://hooks.example.test/token')
        ->press('Register endpoint');

    // Landed on the endpoint's own page, holding the credential it just minted.
    $page->assertSee('Copy this signing secret now')
        ->assertSee('X-Cbox-Signature');

    // GONE ON DEMAND, with no round trip — there is nothing left on the server to ask.
    $page->press('Dismiss')
        ->assertDontSee('Copy this signing secret now')
        ->assertNoJavaScriptErrors();

    // …and gone on the next load either way, which is what "shown once" means. A banner
    // that survived a refresh would be a credential stored where the next person to open
    // the tab can read it.
    $page->navigate($page->url())
        ->assertDontSee('Copy this signing secret now');
})->group('a11y');
