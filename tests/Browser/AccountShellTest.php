<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Platform\Surfaces\SessionSurface;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;

/**
 * A PERSON'S OWN PAGES ON THE LOGIN HOST, ON A PHONE, IN A REAL BROWSER.
 *
 * The login host is the browser's own host here (`127.0.0.1`), so the account pages are
 * drawn with the account chrome rather than inside a console. What is asserted is what a
 * person sees: the way back to the app they came from, their pages as one row of tabs that
 * scrolls sideways, the account menu, and nothing wider than the screen.
 */
beforeEach(function (): void {
    config([
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => 'localhost',
        'cbox-id.hosts.login' => '127.0.0.1',
    ]);

    installedDeployment();
});

function accountOnThePhone(): void
{
    $account = provisionAccount('phone@acme.test');
    platformRootDeployment();

    app(ClientRegistry::class)->register(new NewClient(
        name: 'Acme Billing',
        type: ClientType::Confidential,
        redirectUris: ['https://billing.acme.example/auth/callback'],
    ));

    $session = app(SessionManager::class)->start($account['subjectId'], $account['organization']->id, ['pwd']);
    signInAsMember($account['subjectId']);
    session([PlatformAuth::SESSION_KEY => $session->id, SessionSurface::KEY => 'login']);
}

it('draws the account chrome on a phone with the way back to the app', function (string $theme): void {
    accountOnThePhone();

    $page = visit('/settings?return_to='.urlencode('https://billing.acme.example/invoices'))
        ->resize(390, 844);

    $page->script("document.documentElement.setAttribute('data-theme', '{$theme}')");

    $page->assertVisible('[data-test="account-back"]')
        ->assertSeeIn('[data-test="account-back"]', 'Acme Billing')
        ->assertVisible('nav.cbx-tabs')
        ->assertVisible('.cbx-avatar-btn')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->screenshot(false, 'account-shell-phone-'.$theme);

    // Another of their pages: the way back is still the app's.
    $page->click('nav.cbx-tabs a:nth-child(2)')
        ->assertSeeIn('[data-test="account-back"]', 'Acme Billing')
        ->assertNoJavaScriptErrors();

    $page->screenshot(false, 'account-shell-phone-activity-'.$theme);

    // Language, theme, switching account and signing out: the account menu, as elsewhere.
    $page->click('.cbx-avatar-btn')
        ->assertSee('Language')
        ->assertSee('Theme')
        ->assertSee('Switch account')
        ->assertSee('Sign out')
        ->assertNoJavaScriptErrors();

    $page->screenshot(false, 'account-shell-phone-menu-'.$theme);
})->with(['light', 'dark']);

it('draws the rail on a desktop', function (): void {
    accountOnThePhone();

    $page = visit('/settings?return_to='.urlencode('https://billing.acme.example/invoices'))
        ->resize(1280, 860);

    $page->assertVisible('aside.idc-rail')
        ->assertSeeIn('[data-test="account-back"]', 'Acme Billing')
        ->assertNoJavaScriptErrors();

    $page->screenshot(false, 'account-shell-desktop');
});

it('speaks the person\'s language once they choose it in the account menu', function (): void {
    accountOnThePhone();

    $page = visit('/settings?return_to='.urlencode('https://billing.acme.example/invoices'))
        ->resize(390, 844);

    $page->assertVisible('.cbx-avatar-btn')
        ->click('.cbx-avatar-btn')
        ->click('Language')
        ->click('Dansk')
        ->assertSee('Sikkerhed')
        ->assertSeeIn('[data-test="account-back"]', 'Tilbage til Acme Billing')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.documentElement.lang'))->toBe('da')
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->screenshot(false, 'account-shell-phone-danish');
});
