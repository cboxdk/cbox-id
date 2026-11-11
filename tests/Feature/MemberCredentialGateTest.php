<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlatformAuth;
use App\Platform\RevokingAuthPolicies;
use App\Platform\SubjectCredentialGate;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * {@see SubjectCredentialGate} — the rules that decide whether an account
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
 *  - the ACCOUNT password door (the account plane's own password door), which is now the only
 *    place an account member types a password at all, and
 *  - the HANDOFF, which is not a credential the member just proved but stands in for one.
 *
 * They are not asked the same question, and asking them the same question is what put an
 * SSO-mandated account into a redirect loop between the two consoles. `admits()` is the
 * password rules; {@see SubjectCredentialGate::admitsHandoff()} is the standing facts a
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
function anMembership(): object
{
    platformRootEnvironment();

    return app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
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
    $result = anMembership();
    $subjectId = (string) $result->owner->id;

    app(PlatformRoot::class)->run(fn () => app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subjectId,
        password: 'a-handed-over-temporary-passphrase',
        temporary: true,
        expiresAt: now()->addHour(),
        revoke: PasswordRevocationScope::Nothing,
    )));

    $auth = app(PlatformAuth::class);
    $request = Request::create('/login', 'POST');

    // Inside its window the credential IS admitted by the gate. It does not reach a
    // session — the console holds it on the change page, which is the rule next door —
    // and that is the point of asserting on the outcome rather than on `check()`: this
    // test is about expiry, and a baseline that could not tell "expired" from "held"
    // would pass whatever happened.
    expect(signInAtLogin('owner@acme.example', 'a-handed-over-temporary-passphrase'))
        ->toBe(AttemptOutcome::Ok);

    $this->travel(2)->hours();

    // Past its deadline the hash still matches and the door is shut.
    expect(signInAtLogin('owner@acme.example', 'a-handed-over-temporary-passphrase'))
        ->toBe(AttemptOutcome::Invalid);
})->group('security');

it('locks the account door out at the policy threshold', function (): void {
    anMembership();

    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(lockoutThreshold: 3)),
    );

    $auth = app(PlatformAuth::class);
    $request = Request::create('/login', 'POST');

    foreach (range(1, 3) as $ignored) {
        expect(signInAtLogin('owner@acme.example', 'a-wrong-guess-entirely'))
            ->toBe(AttemptOutcome::Invalid);
    }

    // The RIGHT password is now refused too, and refused identically — the lockout is
    // asked BEFORE the credential, or a locked account still answers differently for a
    // right guess than for a wrong one.
    expect(signInAtLogin('owner@acme.example', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::Invalid);
})->group('security');

/**
 * The environment console is the SaaS route into an environment's administration. It
 * re-resolves the membership on every request — catching a revoked membership or a
 * downgraded role — and must ask, as every other resolve path does, whether the
 * ORGANIZATION behind that member is still active.
 */
it('refuses the console to a member whose organization has been suspended', function (): void {
    $result = anMembership();
    handoffShape($result->environment);
    signInAsMember($result->owner->id);

    $console = consoleUrlOf($result->environment);
    $this->get($console)->assertOk();

    // Suspended while the tab sat open.
    //
    // IN THE PLATFORM ROOT, because that is where a customer's organization lives. Written
    // under the suite's ambient scope this update matches no rows and suspends nothing, so
    // every assertion below would pass or fail for reasons that have nothing to do with a
    // suspension.
    app(PlatformRoot::class)->run(
        fn () => Organization::query()->whereKey($result->organization->id)
            ->update(['status' => OrganizationStatus::Suspended]),
    );

    nextRequest();

    expect($this->get($console)->status())->not->toBe(200)
        ->and(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
})->group('security');

/** The console of an environment, on the app host, as a browser addresses it. */
function consoleUrlOf(Environment $environment, string $page = ''): string
{
    return 'https://cboxid.com/'.app(EnvironmentConsolePath::class)->for($environment->id).$page;
}

/**
 * Walk a redirect chain with a HOP LIMIT and return where it stops.
 *
 * A single-hop `assertRedirect()` cannot tell a refusal from a cycle — both look identical
 * one hop in, which is how the SSO refusal this file used to assert shipped green while
 * being hop one of an infinite chain. `followingRedirects()` cannot be used either: its
 * `while` is unbounded, so a cycle hangs the suite instead of failing it.
 *
 * @return array{0: TestResponse, 1: list<string>}
 */
function chainFrom(TestCase $test, string $url, int $hops = 6): array
{
    $response = $test->get($url);
    $chain = [$url];

    for ($hop = 0; $hop < $hops && $response->isRedirect(); $hop++) {
        nextRequest();

        $location = (string) $response->headers->get('Location');
        $chain[] = $location;
        $response = $test->get($location);
    }

    return [$response, $chain];
}

/**
 * THE ENTERPRISE PATH, WHICH ONCE DID NOT EXIST.
 *
 * An account that mandates SSO is the configuration this product is sold on, and it once
 * could not reach its own tenant console: a handoff redemption asked whether a PASSWORD
 * would be admitted, the SSO mandate answered no forever, and the refusal bounced back to
 * the minting door. The console is on the app host now, in the session the member's SSO
 * sign-in produced, and the old "open" door must still land there in one pass.
 */
it('opens the tenant console for a member whose account mandates SSO', function (): void {
    $result = anMembership();
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

    signInAsMember($result->owner->id);

    // From the old door a bookmark still presses — "open" — to the console it names.
    [$response, $chain] = chainFrom($this, 'https://cboxid.com'.route('environment.open', $result->environment->id, false));

    expect($response->isRedirect())->toBeFalse('the console bounced instead of opening: '.implode(' -> ', $chain))
        ->and(collect($chain)->filter(fn (string $hop): bool => str_contains($hop, '/open/'))->count())
        ->toBe(1, 'the chain re-entered the open door, which is the cycle: '.implode(' -> ', $chain))
        ->and(end($chain))->toBe(consoleUrlOf($result->environment));

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
 * passwords loses it. Not at the next sign-in — an admin session is the member's ordinary
 * platform-root subject session and {@see EnvironmentAdminAuth} re-reads that row every
 * request, so the console closes on the next click.
 */
it('closes an open tenant console when the account\'s policy stops admitting passwords', function (): void {
    $result = anMembership();
    handoffShape($result->environment);
    signInAsMember($result->owner->id);

    [$response] = chainFrom($this, 'https://cboxid.com'.route('environment.open', $result->environment->id, false));
    $response->assertSuccessful();
    expect(app(EnvironmentAdminAuth::class)->check())->toBeTrue();

    // The policy governing an ACCOUNT member is written in the platform root — that is
    // where account members are subjects, and where their sessions live.
    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required)),
    );

    nextRequest();

    [$after, $chain] = chainFrom($this, consoleUrlOf($result->environment));

    expect($after->isRedirect())->toBeFalse('the refusal never landed: '.implode(' -> ', $chain))
        ->and(end($chain))->toBe('https://cboxid.com/login')
        ->and(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
})->group('security');

/**
 * And the refusal that REMAINS on the console ends somewhere a person can act.
 *
 * An administratively-issued temporary password past its deadline stops opening things,
 * and it is a fact about the subject rather than about a door, so the console is right to
 * hold them. What makes that a hold rather than a cycle is the other end: the same
 * requirement row holds the member on the app host's change page, the one screen that can
 * end the requirement.
 *
 * Asserted as a CHAIN for that reason. The refusal and its landing are two facts.
 */
it('ends an expired-password visit to the console on the change page rather than bouncing', function (): void {
    $result = anMembership();
    handoffShape($result->environment);

    $subjectId = (string) $result->owner->id;

    app(PlatformRoot::class)->run(fn () => app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subjectId,
        password: 'a-handed-over-temporary-passphrase',
        temporary: true,
        expiresAt: now()->addHour(),
        revoke: PasswordRevocationScope::Nothing,
    )));

    // Past the deadline, and signed in AFTER it — the account session outlives a temporary
    // password's window, which is the whole reason the requirement is standing rather than
    // asked once at the door. (Signing in before travelling would only prove that a
    // two-hour-old session has expired.)
    $this->travel(2)->hours();
    signInAsMember($result->owner->id);

    [$response, $chain] = chainFrom($this, consoleUrlOf($result->environment, '/users'));

    expect($response->isRedirect())->toBeFalse('the refusal never landed: '.implode(' -> ', $chain))
        ->and(end($chain))->toBe('https://cboxid.com/password/change');

    $response->assertSuccessful();
})->group('security');
