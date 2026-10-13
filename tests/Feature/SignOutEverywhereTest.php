<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
use App\Platform\SignInLineage;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\EnvironmentAdminHandoff;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Testing\TestResponse;
use Tests\Support\Browsers;
use Tests\Support\RootGoogle;

/*
|--------------------------------------------------------------------------
| LOG OUT MEANS LOGGED OUT — on every host the sign-in reached
|--------------------------------------------------------------------------
|
| The owner's report: on `acme.cboxid.com/admin`, Log out landed on the tenant's sign-in,
| and opening `/admin` again showed the console, still signed in. The console's session was
| ended; the ACCOUNT host's was not, and the console's gate sends a signed-out administrator
| to the account host's open-environment door — which minted a fresh handoff straight back
| in. One redirect undid the sign-out.
|
| Every test here keeps one cookie jar per host and per device ({@see Browsers}), because
| the bug lives exactly where the test client's single shared session would hide it.
|
| THE DESIGN, as these tests pin it: a sign-out ends the SIGN-IN — every account this
| browser holds on the host where the button was pressed, the account-host sessions an
| environment console was handed off from, and every console session handed off from those
| ({@see SignInLineage}). Another device's sign-in is a different sign-in and is left alone;
| ending those is "Sign out other sessions", an administrator, or a password reset, all of
| which already revoke every session the person holds, console sessions included.
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
});

/** The account host's open-environment door for this test's environment. */
function signOutOpenDoor(): string
{
    return test()->root.'/open/'.test()->environmentId;
}

/**
 * Sign in at the account host and open the environment's console, as a person does: the
 * console bounces to the account host's door, the door answers with the self-submitting
 * form, and the form posts to the environment's host. Each hop in its own host's jar.
 *
 * @return array<string, string> the handoff form's fields, as the browser posted them
 */
function signInAndOpenConsole(string $device = 'laptop'): array
{
    $t = test();

    $t->browsers->use($device.'@root');
    signInAsSubject($t->subjectId);
    nextRequest();

    $t->browsers->use($device.'@tenant');
    $open = (string) $t->get($t->tenant.'/admin')->assertRedirect()->headers->get('Location');
    expect($open)->toBe(signOutOpenDoor());

    $t->browsers->use($device.'@root');
    $door = $t->get($open)->assertOk();
    $fields = (array) $door->viewData('fields');

    expect($fields)->toHaveKeys(['token', SignInLineage::FIELD]);

    $t->browsers->use($device.'@tenant');
    $t->post((string) $door->viewData('action'), $fields)->assertRedirect(route('environment.home'));
    nextRequest();

    $t->get($t->tenant.'/admin')->assertOk();

    /** @var array<string, string> $fields */
    return $fields;
}

/** The console on the environment's host, from this jar: in, or bounced to the account host's door. */
function consoleAnswer(): TestResponse
{
    nextRequest();

    return test()->get(test()->tenant.'/admin');
}

it('signs a tenant console out of the account host too, so /admin asks for a sign-in again', function (): void {
    signInAndOpenConsole();

    // The console's Sign out posts to the console's own logout — the admin context's.
    $shell = $this->get($this->tenant.'/admin')->assertOk()->inertiaProps('shell');
    expect($shell['logoutHref'])->toBe($this->tenant.'/admin/logout');

    $out = $this->post($this->tenant.'/admin/logout');
    $out->assertRedirect($this->tenant.'/signed-out?from=console');

    // Back in the console: the gate sends them to the account host's door…
    consoleAnswer()->assertRedirect(signOutOpenDoor());

    // …and the account host, whose cookie the button never touched, is signed out as well:
    // the door asks for a sign-in rather than minting a handoff straight back in.
    $this->browsers->use('laptop@root');
    nextRequest();
    $this->get(signOutOpenDoor())->assertRedirect($this->root.'/login');
    nextRequest();
    $this->get($this->root.'/dashboard')->assertRedirect($this->root.'/login');
})->group('security');

it('ends the tenant console when the person logs out at the account host', function (): void {
    signInAndOpenConsole();

    $this->browsers->use('laptop@root');
    $this->post($this->root.'/logout')->assertRedirect($this->root.'/signed-out');

    // The console's own cookie is still there — and buys nothing: its session row is gone.
    $this->browsers->use('laptop@tenant');
    expect(session(EnvironmentAdminAuth::ENV_KEY))->toBe($this->environmentId);

    consoleAnswer()->assertRedirect(signOutOpenDoor());

    $this->browsers->use('laptop@root');
    nextRequest();
    $this->get(signOutOpenDoor())->assertRedirect($this->root.'/login');
})->group('security');

it('ends every console that sign-in opened, in every tab', function (): void {
    signInAndOpenConsole();

    // A second tab shares the cookie, so it is the same session. A second CONSOLE SESSION
    // from the same sign-in is what a second redemption makes — another browser profile,
    // or the same console opened again after its cookie was cleared.
    $this->browsers->use('laptop@root');
    $door = $this->get(signOutOpenDoor())->assertOk();

    $this->browsers->use('laptop-other-profile@tenant');
    $this->post((string) $door->viewData('action'), (array) $door->viewData('fields'))->assertRedirect(route('environment.home'));
    consoleAnswer()->assertOk();

    // Signing out in the first console ends the account host AND the other console session.
    $this->browsers->use('laptop@tenant');
    $this->post($this->tenant.'/admin/logout')->assertRedirect();

    $this->browsers->use('laptop-other-profile@tenant');
    consoleAnswer()->assertRedirect(signOutOpenDoor());
})->group('security');

it('signs out every account the browser holds, everywhere', function (): void {
    [, $secondSubject] = addMember($this->account['organization']->id, MembershipRole::Admin, 'second@acme.example');

    // Two accounts on the account host; the console opened as the active one.
    $this->browsers->use('laptop@root');
    signInAsSubject($secondSubject);
    nextRequest();
    signInAndOpenConsole();

    $this->browsers->use('laptop@root');
    $held = app(PlatformAuth::class)->heldSessionIds();
    expect($held)->toHaveCount(2);

    $this->browsers->use('laptop@tenant');
    $this->post($this->tenant.'/admin/logout')->assertRedirect();

    $active = app(PlatformRoot::class)->run(fn (): array => array_values(array_filter(
        $held,
        fn (string $id): bool => app(SessionManager::class)->active($id) !== null,
    )));

    expect($active)->toBe([]);
})->group('security');

it('leaves the same person\'s other device signed in — that is a different sign-in', function (): void {
    signInAndOpenConsole('laptop');
    signInAndOpenConsole('phone');

    $this->browsers->use('laptop@tenant');
    $this->post($this->tenant.'/admin/logout')->assertRedirect();

    consoleAnswer()->assertRedirect(signOutOpenDoor());

    // The phone's sign-in is untouched, on both hosts.
    $this->browsers->use('phone@tenant');
    consoleAnswer()->assertOk();

    $this->browsers->use('phone@root');
    nextRequest();
    $this->get($this->root.'/dashboard')->assertRedirect(route('projects'));
})->group('security');

it('ends console sessions on every device when all of the person\'s sessions are revoked', function (): void {
    signInAndOpenConsole('laptop');
    signInAndOpenConsole('phone');

    // "Sign out other sessions", an administrator, a password reset: every session the
    // person holds. Console sessions are ordinary platform-root rows, so they go too.
    app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->revokeAllForUser($this->subjectId));

    foreach (['laptop', 'phone'] as $device) {
        $this->browsers->use($device.'@tenant');
        consoleAnswer()->assertRedirect(signOutOpenDoor());
    }
})->group('security');

it('refuses to redeem a handoff again after signing out', function (): void {
    $fields = signInAndOpenConsole();

    $this->browsers->use('laptop@tenant');
    $this->post($this->tenant.'/admin/logout')->assertRedirect();

    // The very form that opened the console, posted again: spent, and its sign-in is over.
    nextRequest();
    $this->post($this->tenant.'/admin/handoff', $fields)->assertRedirect(route('admin.login'));

    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
    consoleAnswer()->assertRedirect(signOutOpenDoor());
})->group('security');

it('refuses a handoff minted before the sign-out, even one never spent', function (): void {
    $this->browsers->use('laptop@root');
    signInAsSubject($this->subjectId);
    nextRequest();

    // The form was rendered — a tab left open on the self-submitting page, or a token
    // caught in flight — and then the person signed out.
    $door = $this->get(signOutOpenDoor())->assertOk();
    $fields = (array) $door->viewData('fields');

    nextRequest();
    $this->post($this->root.'/logout')->assertRedirect();

    $this->browsers->use('laptop@tenant');
    $this->post((string) $door->viewData('action'), $fields)->assertRedirect(route('admin.login'));

    expect(session(EnvironmentAdminAuth::ENV_KEY))->toBeNull();
    consoleAnswer()->assertRedirect(signOutOpenDoor());
})->group('security');

it('refuses a handoff without its sign-in, with a tampered one, or with another token\'s', function (): void {
    $this->browsers->use('laptop@root');
    signInAsSubject($this->subjectId);
    nextRequest();

    $mint = fn (): string => app(EnvironmentAdminHandoff::class)->mint($this->subjectId, $this->environmentId);
    $live = (string) session(PlatformAuth::SESSION_KEY);
    $lineage = app(SignInLineage::class);

    $someoneElse = app(PlatformRoot::class)->run(function (): string {
        [, $other] = addMember($this->account['organization']->id, MembershipRole::Admin, 'other@acme.example');

        return app(SessionManager::class)->start($other, null, ['pwd'])->id;
    });

    $this->browsers->use('laptop@tenant');

    $cases = [
        'no sign-in at all' => fn (string $token): array => ['token' => $token],
        'tampered' => fn (string $token): array => ['token' => $token, SignInLineage::FIELD => substr($lineage->seal($token, [$live]), 0, -4).'AAAA'],
        'sealed for another token' => fn (string $token): array => ['token' => $token, SignInLineage::FIELD => $lineage->seal($mint(), [$live])],
        'somebody else\'s sign-in' => fn (string $token): array => ['token' => $token, SignInLineage::FIELD => $lineage->seal($token, [$someoneElse])],
        'not a sealed value' => fn (string $token): array => ['token' => $token, SignInLineage::FIELD => base64_encode((string) json_encode(['t' => hash('sha256', $token), 'p' => [$live]]))],
    ];

    foreach ($cases as $case => $fields) {
        nextRequest();
        $this->post($this->tenant.'/admin/handoff', $fields($mint()))
            ->assertRedirect(route('admin.login'));

        expect(session(EnvironmentAdminAuth::ENV_KEY))->toBeNull($case.' opened the console');
    }

    // …and the genuine article, for contrast.
    $token = $mint();
    nextRequest();
    $this->post($this->tenant.'/admin/handoff', ['token' => $token, SignInLineage::FIELD => $lineage->seal($token, [$live])])
        ->assertRedirect(route('environment.home'));
})->group('security');

it('lands every sign-out on a signed-out page that renders on both hosts and enters nothing', function (): void {
    signInAndOpenConsole();

    // Even for somebody still signed in: the page never redirects into a console.
    foreach ([['laptop@tenant', $this->tenant], ['laptop@root', $this->root]] as [$jar, $host]) {
        $this->browsers->use($jar);
        nextRequest();

        $page = $this->get($host.'/signed-out')->assertOk();

        expect($page->inertiaPage()['component'])->toBe('auth/signed-out')
            ->and($page->inertiaProps('signInUrl'))->toBe($host.'/login')
            ->and((string) $page->headers->get('Cache-Control'))->toContain('no-store');
    }

    // After an environment console's sign-out, Sign in goes back through that console's
    // gate — which is a page on this host, and only ever that or the sign-in page.
    $this->browsers->use('laptop@tenant');
    nextRequest();
    expect($this->get($this->tenant.'/signed-out?from=console')->assertOk()->inertiaProps('signInUrl'))->toBe($this->tenant.'/admin')
        ->and($this->get($this->tenant.'/signed-out?from=https://evil.example')->inertiaProps('signInUrl'))->toBe($this->tenant.'/login');
});

it('says so in every language the hosted pages speak', function (string $locale): void {
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
})->with(['en', 'da', 'de', 'fr', 'nb', 'sv']);

it('clears the browser\'s page history and keeps console pages out of its caches', function (): void {
    signInAndOpenConsole();

    $this->browsers->use('laptop@tenant');
    nextRequest();
    $console = $this->get($this->tenant.'/admin')->assertOk();

    expect((string) $console->headers->get('Cache-Control'))->toContain('no-store')
        ->and($console->inertiaPage()['encryptHistory'] ?? false)->toBeTrue();

    $this->post($this->tenant.'/admin/logout')->assertRedirect();
    nextRequest();

    expect($this->get($this->tenant.'/signed-out?from=console')->inertiaPage()['clearHistory'] ?? false)->toBeTrue();
})->group('security');

it('lands a plain sign-in at the root on the root, not in a tenant console bounced off earlier', function (): void {
    // Once, signed out, this browser opened the tenant's console: bounced to the account
    // host's door, and from there to its sign-in.
    $this->browsers->use('laptop@tenant');
    $open = (string) $this->get($this->tenant.'/admin')->headers->get('Location');

    $this->browsers->use('laptop@root');
    $this->get($open)->assertRedirect($this->root.'/login');
    expect(session(IntendedUrl::KEY))->toBe($open);

    // They went away. Later they sign in at the root for something else entirely.
    $this->travel(16)->minutes();
    nextRequest();

    $this->from($this->root.'/login')->post($this->root.'/login', [
        'email' => 'owner@acme.example',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertRedirect(route('dashboard'));

    // …and the dashboard is the workspace's home, not a handoff into its one environment.
    nextRequest();
    $this->get($this->root.'/dashboard')->assertRedirect(route('projects'));
})->group('security');

it('still takes somebody who started at a tenant console back to it after signing in at the root', function (): void {
    $this->browsers->use('laptop@tenant');
    $open = (string) $this->get($this->tenant.'/admin/users')->headers->get('Location');
    expect($open)->toBe(signOutOpenDoor().'?to=%2Fadmin%2Fusers');

    $this->browsers->use('laptop@root');
    $this->get($open)->assertRedirect($this->root.'/login');
    nextRequest();

    $this->from($this->root.'/login')->post($this->root.'/login', [
        'email' => 'owner@acme.example',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertRedirect($open);
    nextRequest();

    $door = $this->get($open)->assertOk();

    $this->browsers->use('laptop@tenant');
    $this->post((string) $door->viewData('action'), (array) $door->viewData('fields'))->assertRedirect('/admin/users');
    consoleAnswer()->assertOk();
});

it('honours the root sign-in\'s door only for the door this browser was sent from', function (): void {
    // A stale or foreign intent naming the door, without this browser having been sent from
    // it: dropped. A fresh one for a DIFFERENT door: dropped too.
    $this->browsers->use('laptop@root');
    session()->put(IntendedUrl::KEY, signOutOpenDoor());
    session()->put(IntendedUrl::OPEN_STAMP_KEY, ['url' => $this->root.'/open/env_someone_else', 'at' => now()->getTimestamp()]);

    $this->from($this->root.'/login')->post($this->root.'/login', [
        'email' => 'owner@acme.example',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertRedirect(route('dashboard'));

    expect(session(IntendedUrl::OPEN_STAMP_KEY))->toBeNull();
})->group('security');

/*
|--------------------------------------------------------------------------
| TWO CONTEXTS ON ONE TENANT HOST — and a sign-out ends only its own
|--------------------------------------------------------------------------
|
| A tenant's host serves two contexts through one cookie: the ADMIN context (the
| environment console, a platform-root identity handed off from the account host) and the
| tenant's END-USER context (its hosted sign-in, its My account, its SSO session for the
| tenant's apps). The admin context's sign-out ends the admin context everywhere it reached
| and nothing of the end user's; the end user's ends the end user and nothing of the admin's.
*/

/** A person of the TENANT signed in on its host, in the current jar — the end-user context. */
function signInTenantEndUser(): string
{
    $environment = test()->account['environment'];

    $userId = app(EnvironmentContext::class)->runAs($environment, function (): string {
        $user = app(Subjects::class)->create('end.user@customer.example', 'End User', 'a-strong-unbreached-passphrase');
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

    return test()->get(test()->tenant.'/account');
}

/** Whether a session row is still live, wherever it lives. */
function sessionLive(string $id): bool
{
    $environment = test()->account['environment'];

    return app(EnvironmentContext::class)->runAs(
        $environment,
        fn (): bool => app(SessionManager::class)->active($id) !== null,
    ) || app(PlatformRoot::class)->run(fn (): bool => app(SessionManager::class)->active($id) !== null) === true;
}

it('keeps the tenant\'s end user signed in when the administrator signs out of the console', function (): void {
    signInAndOpenConsole();

    $this->browsers->use('laptop@tenant');
    signInTenantEndUser();
    $endUserSession = (string) session(PlatformAuth::SESSION_KEY);

    // Both contexts at once, on one host: neither sign-in displaced the other.
    endUserAnswer()->assertOk();
    consoleAnswer()->assertOk();

    nextRequest();
    $this->post($this->tenant.'/admin/logout')->assertRedirect($this->tenant.'/signed-out?from=console');

    // The admin context is over, here and at the account host…
    consoleAnswer()->assertRedirect(signOutOpenDoor());
    $this->browsers->use('laptop@root');
    nextRequest();
    $this->get(signOutOpenDoor())->assertRedirect($this->root.'/login');

    // …and the end user is exactly as they were.
    $this->browsers->use('laptop@tenant');
    endUserAnswer()->assertOk();
    expect(sessionLive($endUserSession))->toBeTrue();
})->group('security');

it('keeps the console and the account host signed in when the tenant\'s end user signs out', function (): void {
    signInAndOpenConsole();

    $this->browsers->use('laptop@tenant');
    signInTenantEndUser();
    $endUserSession = (string) session(PlatformAuth::SESSION_KEY);

    // My account's Sign out on the tenant's host: the end user's context, as it always was.
    nextRequest();
    $this->post($this->tenant.'/logout')->assertRedirect($this->tenant.'/login');

    endUserAnswer()->assertRedirect($this->tenant.'/login');
    expect(sessionLive($endUserSession))->toBeFalse();

    // The administrator is still in the console, and still signed in at the account host.
    consoleAnswer()->assertOk();

    $this->browsers->use('laptop@root');
    nextRequest();
    $this->get(signOutOpenDoor())->assertOk();
})->group('security');

it('keeps the console signed in through an app\'s RP-initiated logout on the tenant\'s host', function (): void {
    signInAndOpenConsole();

    $this->browsers->use('laptop@tenant');
    signInTenantEndUser();

    // An app the tenant's users sign in to sends the browser to the end-session endpoint.
    nextRequest();
    $this->get($this->tenant.'/oauth/logout')->assertSuccessful();

    endUserAnswer()->assertRedirect($this->tenant.'/login');
    consoleAnswer()->assertOk();
})->group('security');
