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
        // The person's pages are the phone's tab bar, each one its own tab.
        ->assertVisible('nav.cbx-tabbar')
        ->assertSeeIn('nav.cbx-tabbar', 'Security')
        ->assertSeeIn('nav.cbx-tabbar', 'Sessions & activity')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->screenshot(false, 'account-shell-phone-'.$theme);

    // Another of their pages: the way back is still the app's.
    $page->click('nav.cbx-tabbar a[href="/settings/activity"]')
        ->assertSee('Where you are signed in')
        ->assertSeeIn('[data-test="account-back"]', 'Acme Billing')
        ->assertNoJavaScriptErrors();

    $page->screenshot(false, 'account-shell-phone-activity-'.$theme);

    // The rest of the pages, Switch app and the person: under More.
    $page->click('nav.cbx-tabbar button[aria-controls="cbx-more"]')
        ->assertVisible('#cbx-more')
        ->assertSeeIn('#cbx-more', 'Preferences')
        ->assertSeeIn('#cbx-more', 'Switch app')
        ->assertNoJavaScriptErrors();

    $page->screenshot(false, 'account-shell-phone-menu-'.$theme);
})->with(['light', 'dark']);

it('draws the rail on a desktop, one entry per page and no second column', function (): void {
    accountOnThePhone();

    $page = visit('/settings?return_to='.urlencode('https://billing.acme.example/invoices'))
        ->resize(1280, 860);

    $page->assertVisible('.cbx-rail')
        ->assertPresent('.cbx-rail a[href="/settings"]')
        ->assertPresent('.cbx-rail a[href="/settings/preferences"]')
        ->assertNotPresent('.cbx-subnav')
        ->assertSeeIn('[data-test="account-back"]', 'Acme Billing')
        ->assertNoJavaScriptErrors();

    $page->screenshot(false, 'account-shell-desktop');
});

it('speaks the person\'s language once they choose it on the preferences page', function (): void {
    accountOnThePhone();

    $page = visit('/settings/preferences?return_to='.urlencode('https://billing.acme.example/invoices'))
        ->resize(390, 844);

    $page->assertSee('Time zone')
        ->click('button[aria-label="Language"]')
        ->click('Dansk')
        ->assertSee('Tidszone')
        ->assertSeeIn('[data-test="account-back"]', 'Tilbage til Acme Billing')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.documentElement.lang'))->toBe('da')
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->screenshot(false, 'account-shell-phone-danish');
});
