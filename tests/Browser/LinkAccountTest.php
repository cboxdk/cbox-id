<?php

declare(strict_types=1);

use App\Platform\AccountLinking\LinkHold;
use App\Platform\AccountLinking\PendingLink;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\IdentityLink;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Platform\PlatformRoot;

/*
| "Link your Google account to sn@cbox.dk?" in a real browser, on a phone: the step renders
| at 390 px without sideways scrolling, passes axe in light and dark, and a person can answer
| it with the keyboard alone and land signed in.
*/

beforeEach(function (): void {
    installedDeployment();
});

/** A password account at the platform root, and a Google identity held for it — as the callback holds one. */
function heldGoogleLink(): string
{
    platformRootEnvironment();

    $subject = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->create('sn@cbox.dk', 'Sylvester', 'a-strong-unbreached-passphrase'));

    app(PlatformRoot::class)->run(fn () => app(LinkHold::class)->put(PendingLink::hold(
        principal: new FederatedPrincipal('social:google', 'google|browser', 'sn@cbox.dk', 'Sylvester', raw: ['email_verified' => true]),
        subjectId: $subject->id,
        accountEmail: 'sn@cbox.dk',
        providerLabel: 'Google',
        providerKey: 'google',
        operatorProvider: 'google',
        challenged: false,
        now: now()->getTimestamp(),
    )));

    return $subject->id;
}

it('asks the question at a phone\'s width, without sideways scrolling or accessibility issues', function (): void {
    heldGoogleLink();

    visit('/login/link')
        ->on()->iPhone15()
        ->assertSee('Link your Google account to sn@cbox.dk?')
        ->assertSee('Link and sign in')
        ->assertSee("Don't link")
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'link-accounts-step-390');
})->group('a11y');

it('passes axe in the dark too', function (): void {
    heldGoogleLink();

    visit('/login/link')
        ->on()->iPhone15()
        ->inDarkMode()
        ->assertSee('Link your Google account to sn@cbox.dk?')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'link-accounts-step-390-dark');
})->group('a11y');

it('links and signs in from the keyboard', function (): void {
    $subjectId = heldGoogleLink();

    visit('/login/link')
        ->on()->iPhone15()
        ->fill('answer', 'a-strong-unbreached-passphrase')
        ->keys('#answer', 'Enter')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();

    expect(app(PlatformRoot::class)->run(fn (): int => IdentityLink::query()->where('user_id', $subjectId)->count()))->toBe(1)
        ->and(session()->has(PlatformAuth::SESSION_KEY))->toBeTrue();
});

it('switches to a mailed code and back without leaving the page', function (): void {
    heldGoogleLink();

    visit('/login/link')
        ->on()->iPhone15()
        ->click('Email me a code instead')
        ->assertSee('Code from your email')
        ->assertSee('We sent a 6-digit code to sn@cbox.dk.')
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'link-accounts-step-email-code-390')
        ->click('Use your password instead')
        ->assertSee('Your password')
        ->assertNoJavaScriptErrors();
});

it('shows why a sign-in did not complete, on the sign-in page itself', function (): void {
    // A callback for a connection this environment does not have — the earliest failure.
    visit('/sso/oidc/01JZZZZZZZZZZZZZZZZZZZZZZZ/callback?code=c&state=s')
        ->on()->iPhone15()
        ->assertPathIs('/login')
        ->assertSee('is not available here any more')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'link-accounts-login-reason-390');
})->group('a11y');

it('lists every way in on My account › Sign-in methods', function (): void {
    platformRootEnvironment();

    config([
        'services.google.client_id' => 'browser-client', 'services.google.client_secret' => 'browser-secret',
        'services.github.client_id' => 'browser-client', 'services.github.client_secret' => 'browser-secret',
    ]);

    $subject = app(PlatformRoot::class)->run(function () {
        $subject = app(Subjects::class)->create('sn@cbox.dk', 'Sylvester', 'a-strong-unbreached-passphrase');
        app(Subjects::class)->link($subject->id, new FederatedPrincipal('social:google', 'google|panel', 'sn@cbox.dk', raw: ['provider' => 'google', 'email' => 'sn@cbox.dk']));

        return $subject;
    });

    signInAsMember($subject->id);

    visit('/account')
        ->assertSee('Sign-in methods')
        ->assertSee('sn@cbox.dk · Linked')
        ->assertSee('Connect GitHub')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'link-accounts-my-account');

    visit('/account')
        ->on()->iPhone15()
        ->assertSee('Sign-in methods')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'link-accounts-my-account-390');

    visit('/account')
        ->inDarkMode()
        ->assertSee('Sign-in methods')
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'link-accounts-my-account-dark');
})->group('a11y');
