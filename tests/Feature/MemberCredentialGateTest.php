<?php

declare(strict_types=1);

use App\Http\Controllers\EnvironmentAdminController;
use App\Platform\AccountAuth;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\MemberCredentialGate;
use App\Platform\RevokingAuthPolicies;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\EnvironmentAdminHandoff;
use Cbox\Id\Platform\Enums\AccountStatus;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * {@see MemberCredentialGate} — the rules that decide whether an account
 * member's verified password is still a way in, asked at every door that takes one.
 *
 * There were THREE such doors and the file this replaces was named after one of them: a
 * local "sign in as admin" credential form on the tenant host. That door is gone. No
 * correctly-configured deployment could reach it — single-tenant 404s the whole `/admin`
 * prefix and multi-tenant redirected out of its `mount()` — so it was a second
 * account-credential store openable only on a deployment already misconfigured, and its
 * tests passed only because `Volt::test` bypasses routing and therefore never met either
 * refusal.
 *
 * Two doors are left, and both are here:
 *
 *  - the ACCOUNT password door ({@see AccountAuth::attempt()}), which is now the only
 *    place an account member types a password at all, and
 *  - the HANDOFF, which is not a credential the member just proved but stands in for one.
 *
 * They are not asked the same question, and asking them the same question is what put an
 * SSO-mandated account into a redirect loop between the two consoles. `admits()` is the
 * password rules; {@see MemberCredentialGate::admitsHandoff()} is the standing facts a
 * minted token must still respect. The tests below hold each to its own.
 *
 * The gate has four rules and the other two are already held to elsewhere, on the same
 * account door, so they are not restated here: the SSO mandate by "holds the account door
 * to the SSO mandate on the account's organization" ({@see UnifiedAccountIdentityTest}),
 * and `owesPasswordChange()` by "holds the workspace console until an account member
 * replaces a temporary password" ({@see ForcedPasswordChangeTest}) — the console planes
 * HOLD such a member on a change page rather than refusing, which is the behaviour that
 * outlived the door that refused.
 */
function anAccountMember(): object
{
    platformRootEnvironment();

    return app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
}

/**
 * The SaaS shape the HANDOFF route lives in.
 *
 * The whole `/admin` prefix carries `multi.tenant` — on a single-tenant deployment it
 * 404s, because the lone environment is the platform root and belongs to no account, so
 * nobody could ever be handed off into it. The request must also land on a host resolving
 * to a NON-root environment, or `plane:subject` refuses it with a 404 indistinguishable
 * from the tenancy gate's.
 */
function handoffShape(Environment $environment): void
{
    multiTenantDeployment();
    serveOnTestHost($environment);
}

/**
 * The gap that made this P1 for anyone running a hand-over credential: an administratively
 * issued temporary password stops admitting anyone once its deadline passes, even though
 * the hash still matches — otherwise it lingers as a permanent second way in.
 */
it('refuses the account door once an administrative password has expired', function (): void {
    $result = anAccountMember();
    $subjectId = (string) $result->member->refresh()->subject_id;

    app(PlatformRoot::class)->run(fn () => app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subjectId,
        password: 'a-handed-over-temporary-passphrase',
        temporary: true,
        expiresAt: now()->addHour(),
        revoke: PasswordRevocationScope::Nothing,
    )));

    $auth = app(AccountAuth::class);
    $request = Request::create('/login', 'POST');

    // Inside its window the credential IS admitted by the gate. It does not reach a
    // session — the console holds it on the change page, which is the rule next door —
    // and that is the point of asserting on the outcome rather than on `check()`: this
    // test is about expiry, and a baseline that could not tell "expired" from "held"
    // would pass whatever happened.
    expect($auth->attempt($request, 'owner@acme.example', 'a-handed-over-temporary-passphrase'))
        ->toBe(AttemptOutcome::Ok);

    $this->travel(2)->hours();

    // Past its deadline the hash still matches and the door is shut.
    expect($auth->attempt($request, 'owner@acme.example', 'a-handed-over-temporary-passphrase'))
        ->toBe(AttemptOutcome::Invalid);
})->group('security');

it('locks the account door out at the policy threshold', function (): void {
    anAccountMember();

    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(lockoutThreshold: 3)),
    );

    $auth = app(AccountAuth::class);
    $request = Request::create('/login', 'POST');

    foreach (range(1, 3) as $ignored) {
        expect($auth->attempt($request, 'owner@acme.example', 'a-wrong-guess-entirely'))
            ->toBe(AttemptOutcome::Invalid);
    }

    // The RIGHT password is now refused too, and refused identically — the lockout is
    // asked BEFORE the credential, or a locked account still answers differently for a
    // right guess than for a wrong one.
    expect($auth->attempt($request, 'owner@acme.example', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::Invalid);
})->group('security');

/**
 * The handoff is the SaaS route into the environment admin console. It re-resolved the
 * membership on redemption — catching a revoked membership or a downgraded role — but
 * never asked whether the ACCOUNT behind that member was still active. Every other
 * resolve path does.
 */
it('refuses a handoff for a member whose account has been suspended', function (): void {
    $result = anAccountMember();
    handoffShape($result->environment);

    $subjectId = (string) $result->member->refresh()->subject_id;
    $token = app(EnvironmentAdminHandoff::class)->mint($subjectId, $result->environment->id);

    // Suspended between the mint and the redemption — the tab that sat open.
    Account::query()->whereKey($result->account->id)->update(['status' => AccountStatus::Suspended]);

    // The OUTER wall: `DatabaseEnvironmentResolver::servable()` refuses to resolve an
    // environment whose account is not active, so the tenant host stops resolving at all,
    // falls back to the platform root, and `plane:subject` 404s the whole console. The
    // redemption never reaches a controller.
    $this->get("/admin/handoff?token={$token}")->assertNotFound();

    // The INNER check, driven at the controller because the wall above is now the reason
    // an HTTP request cannot reach it. Not redundant with the wall: host resolution is
    // cached ({@see \Cbox\Id\Organization\CachedEnvironmentResolver}), so a suspension that
    // lands between the mint and the redemption can still meet a warm, servable
    // environment — and this is the check that refuses the token then.
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));

    $response = app()->call(
        [app(EnvironmentAdminController::class), 'handoff'],
        ['request' => Request::create('/admin/handoff', 'GET', ['token' => $token])],
    );

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getTargetUrl())->toBe(route('admin.login'))
        ->and(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
})->group('security');

/**
 * Walk a redirect chain with a HOP LIMIT and return where it stops.
 *
 * A single-hop `assertRedirect()` cannot tell a refusal from a cycle — both look identical
 * one hop in, which is how the SSO refusal this file used to assert shipped green while
 * being hop one of an infinite chain. `followingRedirects()` cannot be used either: its
 * `while` is unbounded, so a cycle hangs the suite instead of failing it.
 *
 * The chain here crosses HOSTS — root, tenant, root — which the harness expresses because
 * every hop is an absolute URL and one session serves both. That is not what a browser
 * does (each host has its own cookies), but it is the loop the browser walks: the tenant
 * half needs no session at all, only the token in the URL.
 *
 * @return array{0: TestResponse, 1: list<string>}
 */
function chainFrom(TestCase $test, string $url, int $hops = 6): array
{
    $response = $test->get($url);
    $chain = [$url];

    for ($hop = 0; $hop < $hops && $response->isRedirect(); $hop++) {
        $location = (string) $response->headers->get('Location');
        $chain[] = $location;
        nextRequest();
        $response = $test->get($location);
    }

    return [$response, $chain];
}

/**
 * THE ENTERPRISE PATH, WHICH DID NOT EXIST.
 *
 * An account that mandates SSO is the configuration this product is sold on, and it could
 * not reach its own tenant console: the root minted a handoff, the redemption asked
 * whether a PASSWORD would be admitted, the SSO mandate answered no forever, and the
 * refusal went to `admin.login` — which is behind the env-admin gate and bounces to the
 * root's minting door. Three hops and back to hop one, for as long as the browser would
 * follow.
 *
 * The mandate governs a DOOR. The handoff is not that door: it is minted from an account
 * session that has already satisfied whatever the account's policy asked of the door it
 * came through, and the tenant cannot see which door that was.
 */
it('opens the tenant console for a member whose account mandates SSO', function (): void {
    $result = anAccountMember();
    handoffShape($result->environment);

    // The mandate FIRST, and the sign-in under it. Written the other way round this test
    // asserted something else once {@see \App\Platform\RevokingAuthPolicies} existed: a
    // session that predates a mandate is now ended by the write, so the chain would have
    // bounced for want of a session rather than opened — and the door this test is about
    // would never have been reached. A member of an SSO-mandated account still HAS an
    // account session; they got it from the provider their account mandated.
    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required)),
    );

    signInAsMember($result->member);

    // From the door the member actually presses — "open" on the account console — so the
    // mint and the redemption are both under test, which is where the cycle lived.
    [$response, $chain] = chainFrom($this, 'https://cboxid.com'.route('environment.open', $result->environment->id, false));

    expect($response->isRedirect())->toBeFalse('the console bounced instead of opening: '.implode(' -> ', $chain))
        ->and(collect($chain)->filter(fn (string $hop): bool => str_contains($hop, '/open/'))->count())
        ->toBe(1, 'the chain re-entered the minting door, which is the cycle: '.implode(' -> ', $chain));

    $response->assertSuccessful();

    // …and it is a real environment-admin session, not merely a page that answered.
    expect(app(EnvironmentAdminAuth::class)->check())->toBeTrue();
})->group('security');

/**
 * THE OTHER HALF OF THAT ARGUMENT, WHICH WAS NOT TRUE WHEN IT WAS MADE.
 *
 * Dropping the password question from the redemption is defensible only because a policy
 * change ENDS the sessions that predate it — otherwise turning the mandate on would have
 * left every live password session walking into the tenant console it used to be refused
 * at, and the availability fix would have bought a security gap.
 *
 * No such revocation existed. It does now, at the write
 * ({@see RevokingAuthPolicies}), and this is the assertion that the two ends
 * meet: a member sitting IN the console when their account's policy stops admitting
 * passwords loses it. Not at the next mint — an admin session is the member's ordinary
 * platform-root subject session and {@see EnvironmentAdminAuth} re-reads that row every
 * request, so the console closes on the next click.
 */
it('closes an open tenant console when the account\'s policy stops admitting passwords', function (): void {
    $result = anAccountMember();
    handoffShape($result->environment);
    signInAsMember($result->member);

    [$response] = chainFrom($this, 'https://cboxid.com'.route('environment.open', $result->environment->id, false));
    $response->assertSuccessful();
    expect(app(EnvironmentAdminAuth::class)->check())->toBeTrue();

    // The policy governing an ACCOUNT member is written in the platform root — that is
    // where account members are subjects, and where their sessions live.
    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required)),
    );

    nextRequest();

    // Addressed by the host the fixture actually serves on, not by a literal.
    // `serveOnTestHost()` takes that host from `app.url`, so a hardcoded `cbox-id.test`
    // is only right on a machine whose `.env` happens to say so — on CI, which copies
    // `.env.example`, the environment answered on `localhost` while the request arrived
    // at `cbox-id.test`, and the chain ended somewhere nobody asserted.
    [$after, $chain] = chainFrom($this, 'https://'.$result->environment->domain.route('environment.home', [], false));

    expect($after->isRedirect())->toBeFalse('the refusal never landed: '.implode(' -> ', $chain))
        ->and(end($chain))->toBe('https://cboxid.com/login')
        ->and(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
})->group('security');

/**
 * And the refusal that REMAINS on a handoff ends somewhere a person can act.
 *
 * An administratively-issued temporary password past its deadline stops opening things,
 * and it is a fact about the subject rather than about a door, so the redemption is right
 * to refuse it. What makes that a refusal rather than a cycle is the other end: the same
 * requirement row holds the member on the account plane's change page, so the bounce back
 * to the minting door lands on the one screen that can end the requirement.
 *
 * Asserted as a CHAIN for that reason. The refusal and its landing are two facts, and this
 * file previously asserted only the first.
 */
it('ends an expired-password handoff on the change page rather than bouncing', function (): void {
    $result = anAccountMember();
    handoffShape($result->environment);

    $subjectId = (string) $result->member->refresh()->subject_id;

    app(PlatformRoot::class)->run(fn () => app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subjectId,
        password: 'a-handed-over-temporary-passphrase',
        temporary: true,
        expiresAt: now()->addHour(),
        revoke: PasswordRevocationScope::Nothing,
    )));

    $token = app(EnvironmentAdminHandoff::class)->mint($subjectId, $result->environment->id);

    // Past the deadline, and signed in AFTER it — the account session outlives a temporary
    // password's window, which is the whole reason the requirement is standing rather than
    // asked once at the door. (Signing in before travelling would only prove that a
    // two-hour-old session has expired.)
    $this->travel(2)->hours();
    signInAsMember($result->member);

    // Same as above: the host the fixture serves on, not the one this file used to name.
    [$response, $chain] = chainFrom($this, 'https://'.$result->environment->domain.'/admin/handoff?token='.$token);

    expect($response->isRedirect())->toBeFalse('the refusal never landed: '.implode(' -> ', $chain))
        ->and(end($chain))->toBe('https://cboxid.com/password/change')
        ->and(app(EnvironmentAdminAuth::class)->check())->toBeFalse();

    $response->assertSuccessful();
})->group('security');
