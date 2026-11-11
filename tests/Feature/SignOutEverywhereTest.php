<?php

declare(strict_types=1);

use App\Platform\Locale\HostedLocale;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Testing\TestResponse;
use Tests\Support\Browsers;
use Tests\Support\RootGoogle;

/*
|--------------------------------------------------------------------------
| LOG OUT MEANS LOGGED OUT — and the environment console is on the app host
|--------------------------------------------------------------------------
|
| The owner's report that started this file: on `acme.cboxid.com/admin`, Log out landed on
| the tenant's sign-in, and opening `/admin` again showed the console, still signed in. The
| console had a session of its own on the tenant's host, handed off from the account host,
| and signing out of one left the other to mint it straight back.
|
| The console lives on the app host now, under the environment's own path, and its
| session IS the app host's: one sign-in, one sign-out. These tests hold what that has to
| mean — Log out ends the console, every account the browser holds, nothing on another
| device; revoking a person's sessions ends their console everywhere; the tenant's own end
| users, on the tenant's host, are untouched by any of it.
|
| One cookie jar per host and per device ({@see Browsers}), because a single shared test
| session would hide exactly the bugs this file is about.
*/

const SIGNOUT_ROOT = RootGoogle::HOST;

beforeEach(function (): void {
    RootGoogle::deployment();

    $this->account = provisionAccount('owner@acme.example');
    $this->subjectId = (string) $this->account['subjectId'];
    $this->environmentId = (string) $this->account['environment']->id;
    $this->tenant = 'https://'.$this->account['environment']->slug.'.'.SIGNOUT_ROOT;
    $this->root = 'https://'.SIGNOUT_ROOT;
    $this->browsers = new Browsers;

    serveConsoleOn(SIGNOUT_ROOT);
    useEnvironmentConsole($this->environmentId);
    $this->console = 'https://'.SIGNOUT_ROOT.envConsolePath();
});

/** Sign in at the app host and open the environment's console there. */
function signInAndOpenConsole(string $device = 'laptop'): void
{
    $t = test();

    $t->browsers->use($device.'@root');
    signInAsSubject($t->subjectId);
    nextRequest();

    $t->get($t->console)->assertOk();
}

/** The console, from this jar: in, or sent to sign in. */
function consoleAnswer(): TestResponse
{
    nextRequest();

    return test()->get(test()->console);
}

it('signs the console out with the app host\'s own Log out', function (): void {
    signInAndOpenConsole();

    // The account menu's Sign out, on the app host the console is served from.
    expect($this->get($this->console)->assertOk()->inertiaProps('shell.account.signOutUrl'))->toBe('/logout')
        ->and(parse_url((string) $this->console, PHP_URL_HOST))->toBe(parse_url((string) $this->root, PHP_URL_HOST));

    $this->post($this->root.'/logout')->assertRedirect($this->root.'/signed-out');

    consoleAnswer()->assertRedirect($this->root.'/login');
    nextRequest();
    $this->get($this->root.'/dashboard')->assertRedirect($this->root.'/login');
})->group('security');

it('signs out every account the browser holds', function (): void {
    [, $secondSubject] = addMember($this->account['organization']->id, MembershipRole::Admin, 'second@acme.example');

    $this->browsers->use('laptop@root');
    signInAsSubject($secondSubject);
    nextRequest();
    signInAndOpenConsole();

    $held = app(PlatformAuth::class)->heldSessionIds();
    expect($held)->toHaveCount(2);

    $this->post($this->root.'/logout')->assertRedirect();

    $active = app(PlatformRoot::class)->run(fn (): array => array_values(array_filter(
        $held,
        fn (string $id): bool => app(SessionManager::class)->active($id) !== null,
    )));

    expect($active)->toBe([]);
})->group('security');

it('leaves the same person\'s other device signed in — that is a different sign-in', function (): void {
    signInAndOpenConsole('laptop');
    signInAndOpenConsole('phone');

    $this->browsers->use('laptop@root');
    $this->post($this->root.'/logout')->assertRedirect();
    consoleAnswer()->assertRedirect($this->root.'/login');

    $this->browsers->use('phone@root');
    consoleAnswer()->assertOk();
})->group('security');

it('ends the console on every device when all of the person\'s sessions are revoked', function (): void {
    signInAndOpenConsole('laptop');
    signInAndOpenConsole('phone');

    app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->revokeAllForUser($this->subjectId));

    foreach (['laptop', 'phone'] as $device) {
        $this->browsers->use($device.'@root');
        consoleAnswer()->assertRedirect($this->root.'/login');
    }
})->group('security');

it('lands every sign-out on a signed-out page that renders on both hosts and enters nothing', function (): void {
    signInAndOpenConsole();

    foreach ([['laptop@tenant', $this->tenant], ['laptop@root', $this->root]] as [$jar, $host]) {
        $this->browsers->use($jar);
        nextRequest();

        $page = $this->get($host.'/signed-out')->assertOk();

        expect($page->inertiaPage()['component'])->toBe('auth/signed-out')
            ->and($page->inertiaProps('signInUrl'))->toBe($host.'/login')
            ->and((string) $page->headers->get('Cache-Control'))->toContain('no-store');
    }

    // Whatever `from` says, Sign in is this host's own sign-in page and nothing else.
    $this->browsers->use('laptop@tenant');
    nextRequest();
    expect($this->get($this->tenant.'/signed-out?from=console')->assertOk()->inertiaProps('signInUrl'))->toBe($this->tenant.'/login')
        ->and($this->get($this->tenant.'/signed-out?from=https://evil.example')->inertiaProps('signInUrl'))->toBe($this->tenant.'/login');
});

it('says so in every language the hosted pages speak', function (string $locale): void {
    config()->set('cbox-id.locales.enabled', HostedLocale::codes());
    $page = $this->get($this->root.'/signed-out?ui_locales='.$locale)->assertOk();

    $messages = $page->inertiaProps('i18n.messages');

    expect($page->inertiaProps('i18n.locale'))->toBe($locale)
        ->and($messages['auth.signed_out.heading'])->toBe(trans('auth.signed_out.heading', [], $locale))
        ->and($messages['auth.signed_out.lead'])->toBe(trans('auth.signed_out.lead', [], $locale))
        ->and($messages['auth.common.sign_in'])->toBe(trans('auth.common.sign_in', [], $locale))
        ->and($page->inertiaProps('title'))->toBe(trans('auth.signed_out.title', [], $locale));

    if ($locale !== 'en') {
        expect($messages['auth.signed_out.heading'])->not->toBe(trans('auth.signed_out.heading', [], 'en'));
    }
})->with(HostedLocale::codes());

it('keeps console pages out of the browser\'s caches and its page history', function (): void {
    signInAndOpenConsole();

    $console = consoleAnswer()->assertOk();

    expect((string) $console->headers->get('Cache-Control'))->toContain('no-store')
        ->and($console->inertiaPage()['encryptHistory'] ?? false)->toBeTrue();

    $this->post($this->root.'/logout')->assertRedirect();
    nextRequest();

    expect($this->get($this->root.'/signed-out')->inertiaPage()['clearHistory'] ?? false)->toBeTrue();
})->group('security');

it('takes somebody who opened a console page signed out back to it after signing in', function (): void {
    $this->browsers->use('laptop@root');
    $this->get($this->console.'/users')->assertRedirect($this->root.'/login');
    nextRequest();

    $this->from($this->root.'/login')->post($this->root.'/login', [
        'email' => 'owner@acme.example',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertRedirect($this->console.'/users');

    nextRequest();
    $this->get($this->console.'/users')->assertOk();
});

it('lands a sign-in at the root on the root when nothing was asked for first', function (): void {
    $this->browsers->use('laptop@root');

    $this->from($this->root.'/login')->post($this->root.'/login', [
        'email' => 'owner@acme.example',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertRedirect(route('dashboard'));
})->group('security');

/*
|--------------------------------------------------------------------------
| THE TENANT'S OWN END USERS — another host, another session
|--------------------------------------------------------------------------
*/

/** A person of the TENANT signed in on its host, in the current jar. */
function signInTenantEndUser(string $email = 'end.user@customer.example'): string
{
    $environment = test()->account['environment'];

    $userId = app(EnvironmentContext::class)->runAs($environment, function () use ($email): string {
        $user = app(Subjects::class)->create($email, 'End User', 'a-strong-unbreached-passphrase');
        app(PlatformAuth::class)->establish(request(), $user->id, ['pwd']);

        return $user->id;
    });

    nextRequest();

    return $userId;
}

/** The tenant's My account, from this jar: the end user's own page, or the tenant's sign-in. */
function endUserAnswer(): TestResponse
{
    nextRequest();

    return test()->get(test()->tenant.'/settings');
}

it('keeps the tenant\'s end user signed in when the administrator signs out of the console', function (): void {
    signInAndOpenConsole();

    $this->browsers->use('laptop@tenant');
    signInTenantEndUser();
    endUserAnswer()->assertOk();

    $this->browsers->use('laptop@root');
    $this->post($this->root.'/logout')->assertRedirect();
    consoleAnswer()->assertRedirect($this->root.'/login');

    $this->browsers->use('laptop@tenant');
    endUserAnswer()->assertOk();
})->group('security');

it('keeps the console signed in when the tenant\'s end user signs out, or an app logs them out', function (): void {
    signInAndOpenConsole();

    $this->browsers->use('laptop@tenant');
    signInTenantEndUser();

    nextRequest();
    $this->post($this->tenant.'/logout')->assertRedirect($this->tenant.'/login');
    endUserAnswer()->assertRedirect($this->tenant.'/login');

    signInTenantEndUser('second.user@customer.example');
    nextRequest();
    $this->get($this->tenant.'/oauth/logout')->assertSuccessful();
    endUserAnswer()->assertRedirect($this->tenant.'/login');

    $this->browsers->use('laptop@root');
    consoleAnswer()->assertOk();
})->group('security');
