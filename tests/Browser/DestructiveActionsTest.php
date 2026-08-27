<?php

declare(strict_types=1);

use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\PlatformRoot;

/**
 * THE CONTROLS THAT DESTROY SOMETHING, DRIVEN THE WAY A PERSON DRIVES THEM.
 *
 * Every test in tests/Feature that covers these posts straight to the route. That proves
 * the SERVER does the right thing with a well-formed request, which is worth proving and
 * is not what this file is about. The dialog between the person and that request is React,
 * and it is the part carrying the safety: a name that must be typed exactly, a button that
 * stays dead until it matches, a consequence spelled out in words. None of that exists in
 * a response body, so no request test can tell whether it is there — or whether the button
 * fires with the field empty.
 *
 * The other half is credentials that are shown ONCE. "Once" is not a property of a
 * response; it is a property of the second one. It can only be checked by loading the page
 * again and looking.
 */
beforeEach(function (): void {
    installedDeployment();
});

/**
 * An owner with an app registered, signed in, sudo fresh.
 *
 * @return array{0: Client, 1: string} the client and its organization id
 */
function anOwnerWithAnApp(): array
{
    platformRootEnvironment();

    [$subject, $org, $client] = app(PlatformRoot::class)->run(function (): array {
        $subject = app(Subjects::class)->create('destructive@acme.test', 'Owner', 'a-strong-unbreached-passphrase');
        app(Subjects::class)->markEmailVerified($subject->id, 'destructive@acme.test');

        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-destructive'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
        app(Projects::class)->createForOrganization($org->id, 'Acme');

        $client = app(ClientRegistry::class)->register(new NewClient(
            'Billing Worker',
            redirectUris: ['https://billing.acme.test/callback'],
            organizationId: $org->id,
        ))->client;

        return [$subject, $org, $client];
    });

    signInAsMember($subject->id);

    // Rotation and deletion both demand a fresh password. That gate is held in
    // ConsoleStepUpTest; opening the window here keeps this file about the dialog.
    app(Sudo::class)->confirm();

    return [$client, $org->id];
}

/**
 * THE BUTTON IS DEAD UNTIL THE NAME MATCHES, and that is the entire safety.
 *
 * A `disabled` attribute in a snapshot proves nothing — React re-renders it on every
 * keystroke, and the failure being designed against is an administrator with staging and
 * production open in two identical tabs. So the dialog is opened, the button is pressed
 * while it should be refusing, and the app is asserted to still exist.
 */
it('will not delete an app until its name is typed exactly', function (): void {
    [$client] = anOwnerWithAnApp();

    $page = visit('/clients/'.$client->id);

    $page->assertSee('Billing Worker')
        ->click('button:has-text("Delete app")');

    // The dialog, with the consequence stated rather than implied.
    $page->assertSee('Delete Billing Worker?')
        ->assertSee('will stop working immediately');

    /*
     * WITH THE FIELD EMPTY the confirm button must be genuinely disabled, not merely
     * ignored on click. Asserted as a STATE rather than by clicking it: a click on a
     * disabled control times out waiting for actionability, which is a five-second way of
     * discovering nothing and reads as a broken test rather than a working guard.
     */
    $page->assertDisabled('.cbx-dialog button:has-text("Delete")');

    /*
     * A NEAR MISS IS A MISS. One letter of case — exactly what an autocapitalising phone
     * keyboard produces, and exactly the bug that shipped in this dialog once.
     */
    $page->fill('.cbx-dialog input.input', 'billing worker');

    $page->assertDisabled('.cbx-dialog button:has-text("Delete")');

    // A PREFIX IS A MISS TOO — the match is the whole name or nothing.
    $page->fill('.cbx-dialog input.input', 'Billing');

    $page->assertDisabled('.cbx-dialog button:has-text("Delete")');

    // Nothing was destroyed on the way through.
    expect(Client::query()->whereKey($client->id)->exists())
        ->toBeTrue('the app was deleted without an exact name');

    $page->assertNoJavaScriptErrors();
})->group('a11y');

/**
 * …AND IT DOES DELETE WHEN THE NAME IS RIGHT.
 *
 * The other half, and not a formality: a confirmation that can never be satisfied is its
 * own outage, and the mobile keyboard bug that shipped in this dialog was exactly that.
 */
it('deletes the app once the name is typed exactly', function (): void {
    [$client] = anOwnerWithAnApp();

    $page = visit('/clients/'.$client->id);

    $page->assertSee('Billing Worker')
        ->click('button:has-text("Delete app")')
        ->fill('.cbx-dialog input.input', 'Billing Worker')
        ->click('.cbx-dialog button:has-text("Delete")');

    // Landed back on the list, and the row is gone.
    $page->assertSee('Apps & API keys')
        ->assertDontSee('Billing Worker')
        ->assertNoJavaScriptErrors();

    expect(Client::query()->whereKey($client->id)->exists())->toBeFalse();
})->group('a11y');

/**
 * A ROTATED SECRET IS SHOWN ONCE, and "once" is a claim about the SECOND page load.
 *
 * The plaintext exists for one render — it is flashed, never written to the page object's
 * history entry — and after that the server holds only a hash. A feature test can assert
 * the flash is present; it cannot assert that a reload does not carry it, because the
 * reload is the thing being tested. So this rotates, reads the secret off the screen, and
 * then asks for the same URL again and requires it to be gone.
 */
it('shows a rotated client secret once and never again', function (): void {
    [$client] = anOwnerWithAnApp();

    $page = visit('/clients/'.$client->id);

    $page->assertSee('Billing Worker')
        ->click('button:has-text("Rotate secret")')
        ->fill('.cbx-dialog input.input', 'Billing Worker')
        ->click('.cbx-dialog button:has-text("Rotate")');

    // The one moment the plaintext exists, announced as such.
    $page->assertSee('Copy your client secret now')
        ->assertNoJavaScriptErrors();

    /*
     * THE SAME URL AGAIN. Not a different page — the point is that returning to the place
     * the secret was shown does not show it, which is what makes "once" true rather than
     * merely "not in the next response".
     */
    $page->navigate('/clients/'.$client->id);

    $page->assertSee('Billing Worker')
        ->assertDontSee('Copy your client secret now')
        // And the page says where it went, rather than leaving a blank where a
        // credential used to be.
        ->assertSee('shown only once');
})->group('a11y');
