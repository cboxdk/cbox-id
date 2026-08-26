<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentSudo;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function signIn(): string
{
    $subject = app(Subjects::class)->create('sudo@acme.test', 'Sudo User', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-sudo'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    // AND THE SESSION KEY THE GUARD READS ON THE WAY IN. `CurrentUser` is resolved state
    // for code already inside the process, which is all a Livewire component ever needed.
    // The step-up screen is a REQUEST now, and without this it answers a redirect to
    // sign-in — so a test asserting the password was refused would be asserting against a
    // bounce to /login.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $subject->id;
}

it('redirects a sensitive action to sudo when not recently confirmed', function (): void {
    signIn();

    regenerateRecoveryCodes()->assertRedirect(route('sudo'));

    /*
     * …and it remembers where to send them back to: the PAGE, taken from the referer,
     * rather than the POST endpoint — otherwise re-entering a password would land somebody
     * on an action by GET and earn them a 405.
     *
     * ROOT-RELATIVE, which is the middleware's own invariant: the value is what sudo later
     * redirects to, so keeping only the path is what stops a crafted referer turning the
     * step-up into an open redirect.
     */
    expect(session()->get('sudo.intended'))->toBe('/account');
});

it('performs the sensitive action once sudo is confirmed', function (): void {
    signIn();
    app(Sudo::class)->confirm(); // fresh step-up

    // 2FA must be enabled for recovery codes; enable it inline first (sudo is already
    // fresh above, so the gated enrol/confirm proceed).
    beginMfaEnrolment();
    confirmMfaEnrolment(app(TotpAuthenticator::class)->codeAt(flashed('mfaSecret'), time()))
        ->assertSessionHasNoErrors();

    regenerateRecoveryCodes()->assertSessionHasNoErrors();

    expect(flashed('recoveryCodes'))->toHaveCount(10);
});

it('sends TOTP enrollment through sudo when not recently confirmed', function (): void {
    signIn();

    // enrollTotp overwrites any existing secret — it must not run from a stale session.
    // Without a fresh step-up, the route redirects to re-auth.
    beginMfaEnrolment()->assertRedirect(route('sudo'));
});

it('sends provider unlink through sudo when not recently confirmed', function (): void {
    $id = signIn();
    app(Subjects::class)->link($id, new FederatedPrincipal('social:google', 'google|1'));

    unlinkSocialProvider('google')->assertRedirect(route('sudo'));
});

it('refuses to unlink a user\'s only sign-in method', function (): void {
    // A social-only account: no password, no passkey, one linked provider.
    $subject = app(Subjects::class)->create('social-only@acme.test', 'Social Only');
    $org = app(Organizations::class)->create(new NewOrganization('Acme2', 'acme-social'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['sso']);
    // The request session too, not only CurrentUser: without it the POST below arrives
    // signed out and is redirected to the door, where "no unlink happened" is true for
    // entirely the wrong reason.
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);
    app(Subjects::class)->link($subject->id, new FederatedPrincipal('social:google', 'google|1'));
    app(Sudo::class)->confirm();

    unlinkSocialProvider('google')->assertSessionHasErrors('unlink');

    // The identity is still linked — the guard blocked the lockout.
    expect(app(Subjects::class)->linkedIdentities($subject->id))->toHaveCount(1);
});

it('allows unlinking when another sign-in method remains', function (): void {
    $id = signIn(); // signIn() creates the account WITH a password
    app(Subjects::class)->link($id, new FederatedPrincipal('social:google', 'google|1'));
    app(Sudo::class)->confirm();

    unlinkSocialProvider('google')->assertSessionHasNoErrors();

    expect(app(Subjects::class)->linkedIdentities($id))->toBeEmpty();
});

it('confirms sudo with the correct password and clears it otherwise', function (): void {
    signIn();

    // Wrong password first — otherwise "it confirmed" is indistinguishable from "it
    // confirms for anybody who loads the form".
    test()->from(route('sudo'))
        ->post(route('sudo.confirm'), ['password' => 'wrong'])
        ->assertSessionHasErrors('password');

    expect(app(Sudo::class)->confirmed())->toBeFalse();

    test()->from(route('sudo'))
        ->post(route('sudo.confirm'), ['password' => 'a-strong-unbreached-passphrase'])
        ->assertSessionHasNoErrors();

    expect(app(Sudo::class)->confirmed())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The `sudo` ROUTE gate — App\Http\Middleware\RequireSudo
|--------------------------------------------------------------------------
| Everything above drives a COMPONENT, which is the inline step-up. The
| middleware is a different defence on different routes, and only its JSON
| branch was covered (one passkey test). Its browser branch — the redirect —
| could be replaced with a pass-through and the whole suite stayed green,
| which meant `/vault` and the social-connect link route, both gated by
| nothing else, were unguarded in fact.
|
| These make a real HTTP request, because that is the only thing that runs
| the middleware: `VaultConsoleTest` drives the Volt component directly and
| never touches routing at all.
*/

/**
 * A session an HTTP REQUEST can authenticate with.
 *
 * `signIn()` above populates CurrentUser in the container, which is enough for
 * `Volt::test()` and nothing else — a real request builds its own container state from
 * the session, so a route test needs the session key written.
 */
function sudoBrowserSession(string $email): string
{
    [$subject, $org] = accountWithOrg($email);

    return app(SessionManager::class)->start($subject->id, $org->id, ['pwd'])->id;
}

it('sends a browser request for a sudo-gated page to the step-up', function (): void {
    $this->withSession([PlatformAuth::SESSION_KEY => sudoBrowserSession('vault-gate@acme.test')]);

    // The AI token vault: reveals stored provider secrets, and the route gate is the
    // ONLY thing standing in front of it.
    $this->get(route('vault'))->assertRedirect(route('sudo'));

    // …and the step-up knows where to send them back to, so the gate is a detour
    // rather than a dead end.
    expect(session('sudo.intended'))->toBe(route('vault'));
});

it('sends a browser request to link a social provider to the step-up', function (): void {
    $this->withSession([PlatformAuth::SESSION_KEY => sudoBrowserSession('connect-gate@acme.test')]);

    // Linking a provider plants a new, durable way in — persistence. A stale session
    // must not be able to do it without a fresh step-up.
    $this->get(route('social.connect', ['provider' => 'google']))->assertRedirect(route('sudo'));
});

it('lets a browser request through once the step-up is fresh', function (): void {
    $this->withSession([
        PlatformAuth::SESSION_KEY => sudoBrowserSession('vault-fresh@acme.test'),
        Sudo::SESSION_KEY => time(),
    ]);

    // The negative above would also pass against a gate that refused EVERYTHING.
    $this->get(route('vault'))->assertOk();
});

/**
 * The return-to a step-up remembers is a redirect target, so it is an open-redirect
 * sink, and it comes from the Referer — a header the caller writes.
 *
 * `str_starts_with($path, '/')` was the whole check, and it admits `//evil.tld/x`:
 * browsers and Laravel's own `UrlGenerator::isValidUrl()` read a leading `//` as a
 * PROTOCOL-RELATIVE absolute URL. A Referer of `https://<our-host>//evil.tld/x` passed
 * the host and scheme comparison honestly, came back out verbatim, and the step-up
 * redirected off-site. That shipped as a fix with nothing guarding it; this is the
 * guard.
 *
 * Driven through the JSON branch at a real gated endpoint rather than at the private
 * method, because the endpoint is where the value is actually stored.
 */
it('never stores an off-site return-to from the referer', function (string $shape): void {
    // Built here rather than in the dataset: a dataset closure is evaluated before the
    // application boots, so `config()` is not resolvable there.
    $origin = rtrim((string) config('app.url'), '/');
    $host = (string) parse_url($origin, PHP_URL_HOST);
    $scheme = (string) parse_url($origin, PHP_URL_SCHEME);

    $referer = match ($shape) {
        // Protocol-relative — the exact bug that shipped. Host and scheme match ours.
        'protocol-relative' => $origin.'//evil.tld/x',
        // The backslash variant browsers normalise to the same thing.
        'backslash' => $origin.'/\\evil.tld/x',
        'cross-host' => $scheme.'://evil.tld/x',
        'cross-scheme' => ($scheme === 'https' ? 'http' : 'https').'://'.$host.'/account',
        default => $shape,
    };

    $this->withSession([PlatformAuth::SESSION_KEY => sudoBrowserSession('referer-'.$shape.'@acme.test')]);

    $this->withHeader('Referer', $referer)
        ->postJson(route('passkeys.register.options'))
        ->assertStatus(403);

    expect(session()->has('sudo.intended'))->toBeFalse();
})->with(['protocol-relative', 'backslash', 'cross-host', 'cross-scheme']);

it('stores a same-origin referer as a root-relative path', function (): void {
    $this->withSession([PlatformAuth::SESSION_KEY => sudoBrowserSession('referer-ok@acme.test')]);

    $origin = rtrim((string) config('app.url'), '/');

    $this->withHeader('Referer', $origin.'/account?tab=security')
        ->postJson(route('passkeys.register.options'))
        ->assertStatus(403);

    // The PATH, not the URL the caller sent — same-origin by construction, whatever
    // authority the header carried.
    expect(session('sudo.intended'))->toBe('/account?tab=security');
});

/*
|--------------------------------------------------------------------------
| The environment plane's step-up
|--------------------------------------------------------------------------
| There was none. `Sudo` guarded the console and a second store guarded the retired
| account plane, and the plane in between — the one whose administrator acts on
| EVERY organization in an environment — had no step-up concept at all. The token
| vault made it visible: identical rotate/grant/revoke actions, a fresh password
| demanded on one plane and nothing asked on the other.
*/

it('confirms an environment step-up against the administrator\'s platform-root password', function (): void {
    $setup = crudSetup();

    expect(app(EnvironmentSudo::class)->confirmed())->toBeFalse();

    // Wrong password first — otherwise "it confirmed" is indistinguishable from "it
    // confirms for anyone who loads the form".
    test()->from(route('environment.sudo'))
        ->post(route('environment.sudo.confirm'), ['password' => 'not-the-password'])
        ->assertSessionHasErrors('password');

    expect(app(EnvironmentSudo::class)->confirmed())->toBeFalse();

    test()->from(route('environment.sudo'))
        ->post(route('environment.sudo.confirm'), ['password' => 'a-strong-unbreached-passphrase'])
        ->assertSessionHasNoErrors();

    expect(app(EnvironmentSudo::class)->confirmed())->toBeTrue()
        // The administrator is a subject of the PLATFORM ROOT, not of the environment
        // being administered, so the verification has to cross into the root. Under the
        // tenant's ambient scope the lookup finds nothing and refuses forever.
        ->and($setup['subjectId'])->not->toBe('');
})->group('security');

it('never lets an environment step-up satisfy the console', function (): void {
    crudSetup();

    app(EnvironmentSudo::class)->confirm();

    // Two keys, and the separation that survives the merge is the one that was always
    // about AUTHORITY rather than about which shell rendered: confirming you hold an
    // environment — every organization in it — must not confirm you as yourself. The
    // account plane's third key is gone with the plane; its pages raise this one.
    expect(app(Sudo::class)->confirmed())->toBeFalse();
})->group('security');

/**
 * THE ORGANIZATION PLANE NEVER READS THE ENVIRONMENT PLANE'S SELECTION — and that is what
 * keeps a console step-up from travelling.
 *
 * `Sudo::SESSION_KEY`'s docblock names "switching organization" as a transition that must
 * forget it. Two paths switch. `PlatformAuth::switchOrganization()` drops both step-ups;
 * `ConsoleScope::chooseOrganization()` drops only the environment one. That looks like a
 * hole — `sudo` gates the Token Vault, which is organization-scoped — and it is not one,
 * because `chooseOrganization()` writes `SELECTION_KEY`, which `organizationId()` reads
 * ONLY on the environment plane. On the organization plane the org comes from the session,
 * so an environment administrator's choice cannot move which organization the vault routes
 * (`plane:console`) act for.
 *
 * So the safety rests on plane separation, not on the step-up being dropped — two distant
 * decisions that happen to agree. This pins the one actually carrying it, because if the
 * organization plane ever started honouring the selection, the retained confirmation would
 * become exactly the hole it looks like, and nothing else would notice.
 */
it('never lets the environment plane\'s organization selection reach the organization plane', function (): void {
    $subjectId = signIn();

    $second = app(Organizations::class)->create(new NewOrganization('Beta', 'beta-sudo'));
    app(Memberships::class)->add($second->id, $subjectId, MembershipRole::Owner);

    // The environment plane's key, planted directly — no plane guard to satisfy, and the
    // point is what the ORGANIZATION plane does with it.
    session()->put(ConsoleScope::SELECTION_KEY, $second->id);

    $scope = app(ConsoleScope::class);

    expect($scope->plane())->toBe(ConsolePlane::Organization)
        // The session's organization, never the planted selection.
        ->and($scope->organizationId())->not->toBe($second->id);
})->group('security');
