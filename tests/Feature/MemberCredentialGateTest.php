<?php

declare(strict_types=1);

use App\Http\Controllers\EnvironmentAdminController;
use App\Platform\AccountAuth;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\MemberCredentialGate;
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
    $request = Request::create('/workspace/login', 'POST');

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
    $request = Request::create('/workspace/login', 'POST');

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

it('refuses a handoff when the policy mandates SSO', function (): void {
    $result = anAccountMember();
    handoffShape($result->environment);

    $subjectId = (string) $result->member->refresh()->subject_id;
    $token = app(EnvironmentAdminHandoff::class)->mint($subjectId, $result->environment->id);

    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required)),
    );

    $this->get("/admin/handoff?token={$token}")->assertRedirect(route('admin.login'));

    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
})->group('security');
