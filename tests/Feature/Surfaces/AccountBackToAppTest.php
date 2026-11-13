<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Platform\Locale\ConsoleLabels;
use App\Platform\PlatformAuth;
use App\Platform\Surfaces\SessionSurface;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;

/**
 * "BACK TO <APP>" ON A PERSON'S OWN PAGES.
 *
 * Somebody who opens their account from an app (its "Manage account" link, the app
 * switcher, a `return_to`) is offered the way back to that app by name, on every one of
 * their pages until they leave. Only to an address this deployment already sends people
 * to: the console, or a host one of this environment's apps registered a redirect URI on.
 * Anything else falls back to the console, or on a customer's host to nothing.
 */
const BACK_LOGIN = 'https://'.LOGIN_HOST;
const BACK_APP = 'https://billing.acme.example';

/** Login-host mode, a person signed in on the login host, and one registered app. */
function backToAppWorld(): void
{
    loginHostMode();
    $account = provisionAccount();
    platformRootDeployment();

    app(ClientRegistry::class)->register(new NewClient(
        name: 'Acme Billing',
        type: ClientType::Confidential,
        redirectUris: [BACK_APP.'/auth/callback'],
    ));

    signInAsMember($account['subjectId']);
    session()->put(SessionSurface::KEY, 'login');
}

/** The console's own start: where "Back to" leads when nobody named anywhere. */
function consoleFallback(): array
{
    return ['url' => 'https://'.APP_HOST.'/auth/start', 'label' => 'Cbox ID'];
}

it('names the app a return_to came from, and keeps it across the person\'s pages', function (): void {
    backToAppWorld();

    $from = BACK_APP.'/invoices?page=2';

    expect($this->get(BACK_LOGIN.'/settings?return_to='.urlencode($from))->assertOk()->inertiaProps('accountShell.back'))
        ->toBe(['url' => $from, 'label' => 'Acme Billing']);

    // Another tab, as a page load and as an Inertia visit: the same way back.
    expect($this->get(BACK_LOGIN.'/settings/activity')->assertOk()->inertiaProps('accountShell.back'))
        ->toBe(['url' => $from, 'label' => 'Acme Billing']);

    $visit = $this->get(BACK_LOGIN.'/settings/api-keys', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'Referer' => BACK_LOGIN.'/settings/activity',
    ])->assertOk();

    expect($visit->json('props.accountShell.back'))->toBe(['url' => $from, 'label' => 'Acme Billing']);
})->group('security');

it('names the app whose page linked to the account when it sent no return_to', function (): void {
    backToAppWorld();

    // A browser sends only the origin across sites, by default.
    $this->get(BACK_LOGIN.'/settings', ['Referer' => BACK_APP.'/'])->assertOk();

    expect($this->get(BACK_LOGIN.'/settings/activity', ['Referer' => BACK_LOGIN.'/settings'])->inertiaProps('accountShell.back'))
        ->toBe(['url' => BACK_APP.'/', 'label' => 'Acme Billing']);
})->group('security');

it('offers no address this deployment does not send people to, and falls back to the console', function (string $address): void {
    backToAppWorld();

    expect($this->get(BACK_LOGIN.'/settings?return_to='.urlencode($address))->assertOk()->inertiaProps('accountShell.back'))
        ->toBe(consoleFallback());

    expect($this->get(BACK_LOGIN.'/settings', ['Referer' => $address])->assertOk()->inertiaProps('accountShell.back'))
        ->toBe(consoleFallback());
})->with([
    'a foreign host' => 'https://evil.example/x',
    'a look-alike of the app' => 'https://billing.acme.example.evil.example/',
    'the app over plain http' => 'http://billing.acme.example/',
    'credentials before the host' => 'https://billing.acme.example@evil.example/',
    'a script' => 'javascript:alert(1)',
])->group('security');

it('keeps the app it knows when a later link names nothing it trusts', function (): void {
    backToAppWorld();

    $this->get(BACK_LOGIN.'/settings?return_to='.urlencode(BACK_APP.'/'))->assertOk();
    $this->get(BACK_LOGIN.'/settings?return_to='.urlencode('https://evil.example/'), ['Referer' => 'https://evil.example/'])->assertOk();

    expect($this->get(BACK_LOGIN.'/settings/activity')->inertiaProps('accountShell.back'))
        ->toBe(['url' => BACK_APP.'/', 'label' => 'Acme Billing']);
})->group('security');

it('gives the account pages the console\'s chrome: their pages, switching account and signing out', function (): void {
    backToAppWorld();

    $shell = $this->get(BACK_LOGIN.'/settings')->assertOk()->inertiaProps('accountShell');

    expect($shell['switchAccountHref'])->toBe('/settings/accounts')
        ->and($shell['signOutHref'])->toBe('/logout')
        ->and(array_column($shell['pages'], 'route'))->toContain('account', 'account.activity')
        ->and(collect($shell['pages'])->firstWhere('route', 'account')['active'])->toBeTrue();
});

it('draws the connected services page in the account chrome, not as a sign-in card', function (): void {
    backToAppWorld();

    $page = $this->get(BACK_LOGIN.'/settings/connected-services')->assertOk();

    expect($page->inertiaPage()['component'])->toBe('auth/connected-services')
        ->and($page->inertiaProps('accountShell.back'))->toBe(consoleFallback());

    expect(file_get_contents(resource_path('js/pages/auth/connected-services.tsx')))
        ->toContain('<AccountLayout>');
});

it('offers the way back to a customer\'s app on its own host, and nothing else there', function (): void {
    loginHostMode();
    $account = provisionAccount();
    $environment = $account['environment'];
    $tenant = 'https://'.$environment->slug.'.cboxauth.test';

    app(EnvironmentContext::class)->runAs($environment, function (): void {
        app(ClientRegistry::class)->register(new NewClient(
            name: 'Acme Portal',
            type: ClientType::Confidential,
            redirectUris: ['https://portal.acme.example/callback'],
        ));

        $person = app(Subjects::class)->create('end.user@customer.example', 'End User', 'a-strong-unbreached-passphrase');
        app(PlatformAuth::class)->establish(request(), $person->id, ['pwd']);
    });
    session()->put(SessionSurface::KEY, 'tenant:'.$environment->slug.'.cboxauth.test');
    nextRequest();

    // No origin: no "Back to" — this page is that person's home here.
    expect($this->get($tenant.'/settings')->assertOk()->inertiaProps('accountBack'))->toBeNull();

    // The environment's own app, by name, across their pages.
    $this->get($tenant.'/settings?return_to='.urlencode('https://portal.acme.example/home'))->assertOk();

    expect($this->get($tenant.'/settings/activity')->assertOk()->inertiaProps('accountBack'))
        ->toBe(['url' => 'https://portal.acme.example/home', 'label' => 'Acme Portal']);

    // Another environment's app is not this one's to send people to.
    $this->get($tenant.'/settings?return_to='.urlencode(BACK_APP.'/'))->assertOk();

    expect($this->get($tenant.'/settings')->inertiaProps('accountBack.url'))->toBe('https://portal.acme.example/home');
})->group('security');

it('is null off a customer\'s host, where the account chrome carries the way back', function (): void {
    backToAppWorld();

    expect($this->get(BACK_LOGIN.'/settings')->assertOk()->inertiaProps('accountBack'))->toBeNull();
});

it('names every page of a person\'s own area in their language', function (): void {
    app()->setLocale('da');

    expect(ConsoleLabels::navigation('Trusted devices'))->toBe('Betroede enheder')
        ->and(ConsoleLabels::navigation('Services'))->toBe('Tjenester')
        ->and(ConsoleLabels::navigation('Security'))->toBe(__('console.security_8f6fb4eb'))
        // Text the catalogue does not have stays as written.
        ->and(ConsoleLabels::navigation('Nothing translated here'))->toBe('Nothing translated here');
});
