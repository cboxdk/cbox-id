<?php

declare(strict_types=1);

use App\Http\Controllers\EnvironmentAdminController;
use App\Platform\EnvironmentAdminAuth;
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
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * The environment admin console has THREE ways in — the local password door
 * (`/admin/login`, the self-hosted route), the signed handoff from the account console
 * (the SaaS route), and an existing session. The password door checked neither the SSO
 * mandate nor administrative password expiry, and the handoff re-checked the member but
 * not the ACCOUNT behind them.
 *
 * Both now ask `MemberCredentialGate`, the same object the account door asks. These tests
 * exist so the three can never drift apart again.
 */
function adminDoorSetup(): object
{
    platformRootEnvironment();

    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    // The local password door only exists on a single-host deployment; with base domains
    // set, both the middleware and the component bounce to the root instead.
    config(['cbox-id.environments.base_domains' => []]);

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));

    return $result;
}

/**
 * The SaaS shape the HANDOFF route lives in, on top of {@see adminDoorSetup()}.
 *
 * The handoff is the account console's way into a tenant's admin console, and the whole
 * `/admin` prefix carries `multi.tenant` — on a single-tenant deployment it 404s, because
 * the lone environment is the platform root and belongs to no account, so nobody could
 * ever be handed off into it. The local-password tests above deliberately do NOT get this:
 * they drive the component directly and are about {@see MemberCredentialGate}, not routing.
 */
function handoffShape(Environment $environment): void
{
    multiTenantDeployment();

    // …and the request must land on a host that resolves to a NON-root environment, or
    // `plane:subject` refuses it with a 404 indistinguishable from the tenancy gate's.
    serveOnTestHost($environment);
}

it('lets a valid account member through the local admin door', function (): void {
    $result = adminDoorSetup();

    Volt::test('admin.login')
        ->set('email', 'owner@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(app(EnvironmentAdminAuth::class)->subjectId())
        ->toBe($result->member->refresh()->subject_id);
});

/**
 * The gap that made this P1 for anyone self-hosting: an environment mandating SSO could
 * still be entered with a local password here, because this door never asked.
 */
it('refuses the local admin door when the policy mandates SSO', function (): void {
    adminDoorSetup();

    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required)),
    );

    Volt::test('admin.login')
        ->set('email', 'owner@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
});

it('refuses the local admin door once an administrative password has expired', function (): void {
    $result = adminDoorSetup();
    $subjectId = (string) $result->member->refresh()->subject_id;

    app(PlatformRoot::class)->run(fn () => app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subjectId,
        password: 'a-handed-over-temporary-passphrase',
        temporary: true,
        expiresAt: now()->addHour(),
        revoke: PasswordRevocationScope::Nothing,
    )));

    // Inside its window the credential works…
    Volt::test('admin.login')
        ->set('email', 'owner@acme.example')
        ->set('password', 'a-handed-over-temporary-passphrase')
        ->call('authenticate')
        ->assertHasErrors('email'); // …except it also owes a change, refused below.

    $this->travel(2)->hours();

    // …and past its deadline the hash still matches but the door is shut.
    Volt::test('admin.login')
        ->set('email', 'owner@acme.example')
        ->set('password', 'a-handed-over-temporary-passphrase')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
});

/**
 * The admin console refuses rather than holds: it is the highest-privilege surface on a
 * tenant, and it has no page on which an account credential can be changed.
 */
it('refuses a temporary password at the admin door and says why', function (): void {
    $result = adminDoorSetup();
    $subjectId = (string) $result->member->refresh()->subject_id;

    app(PlatformRoot::class)->run(fn () => app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subjectId,
        password: 'a-handed-over-temporary-passphrase',
        temporary: true,
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    )));

    $component = Volt::test('admin.login')
        ->set('email', 'owner@acme.example')
        ->set('password', 'a-handed-over-temporary-passphrase')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse();

    // Not the uniform "wrong credentials" — they ARE authenticated, so there is nothing
    // left to disclose, and that message would send them in circles.
    $component->assertSee('must be replaced', escape: false);
});

it('locks the local admin door out at the policy threshold', function (): void {
    adminDoorSetup();

    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(lockoutThreshold: 3)),
    );

    foreach (range(1, 3) as $ignored) {
        Volt::test('admin.login')
            ->set('email', 'owner@acme.example')
            ->set('password', 'a-wrong-guess-entirely')
            ->call('authenticate')
            ->assertHasErrors('email');
    }

    // The RIGHT password is now refused too.
    Volt::test('admin.login')
        ->set('email', 'owner@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
});

/**
 * The handoff is the SaaS route into the same console. It re-resolved the membership on
 * redemption — catching a revoked membership or a downgraded role — but never asked
 * whether the ACCOUNT behind that member was still active. Every other resolve path
 * does.
 */
it('refuses a handoff for a member whose account has been suspended', function (): void {
    $result = adminDoorSetup();
    handoffShape($result->environment);

    $subjectId = (string) $result->member->refresh()->subject_id;
    $token = app(EnvironmentAdminHandoff::class)->mint($subjectId, $result->environment->id);

    // Suspended between the mint and the redemption — the tab that sat open.
    Account::query()->whereKey($result->account->id)->update(['status' => AccountStatus::Suspended]);

    // The OUTER wall, which the SaaS shape put in front of this door and which the
    // single-tenant baseline hid: `DatabaseEnvironmentResolver::servable()` refuses to
    // resolve an environment whose account is not active, so the tenant host stops
    // resolving at all, falls back to the platform root, and `plane:subject` 404s the
    // whole console. The redemption never reaches a controller.
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
});

it('refuses a handoff when the policy mandates SSO', function (): void {
    $result = adminDoorSetup();
    handoffShape($result->environment);

    $subjectId = (string) $result->member->refresh()->subject_id;
    $token = app(EnvironmentAdminHandoff::class)->mint($subjectId, $result->environment->id);

    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required)),
    );

    $this->get("/admin/handoff?token={$token}")->assertRedirect(route('admin.login'));

    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
});
