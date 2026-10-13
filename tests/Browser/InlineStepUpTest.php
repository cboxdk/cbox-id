<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Contracts\WebAuthnVerifier;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\Testing\FakeWebAuthnVerifier;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;

/*
|--------------------------------------------------------------------------
| "CONFIRM IT'S YOU", WHERE YOU ARE — in a real browser.
|--------------------------------------------------------------------------
|
| The bug these hold: a step-up sent the browser to a full-page password screen that came
| back to the page and never to the action. "Add passkey" ended on My account with no
| passkey; every gated button did the same. Now the dialog opens over the page, and the
| click that raised it is the click that completes — without the address changing.
|
| No step-up window is opened by the fixtures here: the whole point is to meet the gate.
*/

beforeEach(function (): void {
    installedDeployment();
});

/** A person of the platform root with a password ('supersecret123'), signed in, no step-up. */
function stepUpBrowserPerson(): string
{
    platformRootEnvironment();

    $subject = app(PlatformRoot::class)->run(function () {
        $subject = app(Subjects::class)->create('step-up-browser@acme.test', 'Ada Lovelace', 'supersecret123');
        app(Subjects::class)->markEmailVerified($subject->id, 'step-up-browser@acme.test');
        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-step-up-browser'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

        return $subject;
    });

    signInAsMember($subject->id);

    return $subject->id;
}

/**
 * A stand-in for the platform authenticator, installed in the page.
 *
 * It answers `navigator.credentials.create()` the way a passkey provider does — with an
 * attestation whose authenticator data names iCloud Keychain's AAGUID — and, like a real
 * browser, REFUSES when the call is not inside a user gesture (`userActivation`). That is
 * the property the dialog's last step exists for: the ceremony has to run in a click.
 */
const FAKE_AUTHENTICATOR = <<<'JS'
(() => {
    window.__passkeyCalls = [];
    const bytes = (text) => Array.from(text, (c) => c.charCodeAt(0));
    navigator.credentials.create = async () => {
        const active = navigator.userActivation ? navigator.userActivation.isActive : true;
        window.__passkeyCalls.push(active);
        if (!active) {
            throw new DOMException('A user gesture is required.', 'NotAllowedError');
        }
        const authData = new Uint8Array(55);
        authData[32] = 0x45;
        authData.set([0xfb, 0xfc, 0x30, 0x07, 0x15, 0x4e, 0x4e, 0xcc, 0x8c, 0x0b, 0x6e, 0x02, 0x05, 0x57, 0xd7, 0xbd], 37);
        const head = [0xa3, 0x63, ...bytes('fmt'), 0x64, ...bytes('none'), 0x67, ...bytes('attStmt'), 0xa0, 0x68, ...bytes('authData'), 0x58, authData.length];
        const attestation = new Uint8Array(head.length + authData.length);
        attestation.set(head);
        attestation.set(authData, head.length);
        return {
            id: 'browser-credential',
            type: 'public-key',
            response: {
                clientDataJSON: new TextEncoder().encode('{}').buffer,
                attestationObject: attestation.buffer,
                getTransports: () => ['internal'],
            },
        };
    };
})();
JS;

it('adds a passkey through the step-up dialog, without leaving the page, and names it', function (): void {
    $subjectId = stepUpBrowserPerson();

    // The authenticator's half is faked; everything the server does is real.
    app()->instance(WebAuthnVerifier::class, new FakeWebAuthnVerifier('browser-credential'));
    app()->forgetInstance(Passkeys::class);

    $page = visit('/account');
    $page->assertSee('Passkeys')->script(FAKE_AUTHENTICATOR);

    $page->click('button:has-text("Add passkey")');

    // Asked HERE, with the reason, rather than sent to /sudo.
    $page->assertSee("Confirm it's you")
        ->assertSee('Adding a passkey gives you a new way to sign in.')
        ->assertPathIs('/account')
        ->assertNoAccessibilityIssues();

    $page->fill('.cbx-dialog input[name="password"]', 'supersecret123')
        ->click('.cbx-dialog button:has-text("Confirm")');

    // The last step is a click, because the ceremony needs one.
    $page->click('.cbx-dialog button:has-text("Continue: create passkey")');

    // Named after the provider that made it, and offered for renaming straight away.
    $page->assertSee('Passkey added — name it')
        ->assertValue('.cbx-dialog input[name="name"]', 'iCloud Keychain')
        ->assertNoAccessibilityIssues();

    $page->fill('.cbx-dialog input[name="name"]', 'Work laptop')
        ->click('.cbx-dialog button:has-text("Save name")');

    $page->assertSee('Work laptop')
        ->assertSee('Passkey renamed.')
        ->assertPathIs('/account')
        ->assertNoJavaScriptErrors();

    // The ceremony ran once, inside the person's click.
    expect($page->script('window.__passkeyCalls'))->toBe([true]);

    expect(app(PlatformRoot::class)->run(
        fn () => WebAuthnCredential::query()->where('user_id', $subjectId)->pluck('name')->all(),
    ))->toBe(['Work laptop']);
})->group('a11y');

it('keeps the default name when the person skips naming', function (): void {
    $subjectId = stepUpBrowserPerson();

    app()->instance(WebAuthnVerifier::class, new FakeWebAuthnVerifier('browser-credential'));
    app()->forgetInstance(Passkeys::class);

    $page = visit('/account');
    $page->assertSee('Passkeys')->script(FAKE_AUTHENTICATOR);

    $page->click('button:has-text("Add passkey")')
        ->fill('.cbx-dialog input[name="password"]', 'supersecret123')
        ->click('.cbx-dialog button:has-text("Confirm")')
        ->click('.cbx-dialog button:has-text("Continue: create passkey")')
        ->click('.cbx-dialog button:has-text("Keep this name")');

    $page->assertSee('iCloud Keychain')->assertPathIs('/account');

    expect(app(PlatformRoot::class)->run(
        fn () => WebAuthnCredential::query()->where('user_id', $subjectId)->value('name'),
    ))->toBe('iCloud Keychain');
});

it('refuses a wrong password in the dialog and runs nothing', function (): void {
    $subjectId = stepUpBrowserPerson();

    config(['services.google.client_id' => 'step-up-client', 'services.google.client_secret' => 'step-up-secret']);
    app(PlatformRoot::class)->run(
        fn () => app(Subjects::class)->link($subjectId, new FederatedPrincipal('social:google', 'google|step-up')),
    );

    $page = visit('/account');

    $page->click('button[aria-label^="Disconnect Google"]')
        ->click('.cbx-dialog button:has-text("Disconnect")');

    $page->assertSee("Confirm it's you")
        ->fill('.cbx-dialog input[name="password"]', 'not-my-password')
        ->click('.cbx-dialog button:has-text("Confirm")');

    $page->assertSee('That password is incorrect.')
        ->assertSee("Confirm it's you");

    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->linkedIdentities($subjectId)))->toHaveCount(1);
});

it('replays a gated write once the password is in, and stays on the page', function (): void {
    $subjectId = stepUpBrowserPerson();

    config(['services.google.client_id' => 'step-up-client', 'services.google.client_secret' => 'step-up-secret']);
    app(PlatformRoot::class)->run(
        fn () => app(Subjects::class)->link($subjectId, new FederatedPrincipal('social:google', 'google|step-up')),
    );

    $page = visit('/account');

    $page->click('button[aria-label^="Disconnect Google"]')
        ->click('.cbx-dialog button:has-text("Disconnect")');

    $page->assertSee("Confirm it's you")
        ->assertSee('Disconnecting an account changes how you sign in.')
        ->fill('.cbx-dialog input[name="password"]', 'supersecret123')
        ->click('.cbx-dialog button:has-text("Confirm")');

    // The disconnect the person asked for, done — not a password screen and a page to
    // find the button on again.
    $page->assertSee('Disconnected. You can no longer sign in with it.')
        ->assertPathIs('/account')
        ->assertNoJavaScriptErrors();

    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->linkedIdentities($subjectId)))->toBeEmpty();
});

it('asks for the environment plane in place before a gated page, then opens it', function (): void {
    // "Set up" on Directory Sync opens a page that mints a SCIM bearer token, so it asks
    // for the environment plane's step-up at the door (ConsoleStepUp, from the controller).
    // The browser's server answers on 127.0.0.1: make that the multi-tenant root's host.
    multiTenantDeployment('127.0.0.1');
    provisionAccount('owner@acme.example');

    $operator = actAsOperator('step-up-ops@platform.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified((string) $operator->subject_id, 'step-up-ops@platform.test'));

    $page = visit('/admin/connectors');

    $page->click('role=link[name="Set up Directory Sync"]');

    // Still on the page the link was on, asked for THIS plane's password: the platform-root
    // subject's, checked by /admin/sudo.
    $page->assertSee("Confirm it's you")
        ->assertPathIs('/admin/connectors')
        ->fill('.cbx-dialog input[name="password"]', 'a-strong-operator-pass')
        ->click('.cbx-dialog button:has-text("Confirm")');

    $page->assertPathIs('/admin/sync-in/new')
        ->assertDontSee("Confirm it's you")
        ->assertNoJavaScriptErrors();
});
