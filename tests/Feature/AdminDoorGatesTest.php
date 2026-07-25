<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\EnvironmentAdminHandoff;
use Cbox\Id\Platform\Enums\AccountStatus;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('lets a valid account member through the local admin door', function (): void {
    $result = adminDoorSetup();

    Volt::test('admin.login')
        ->set('email', 'owner@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(session()->get(EnvironmentAdminAuth::SESSION_KEY))
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

    expect(session()->get(EnvironmentAdminAuth::SESSION_KEY))->toBeNull();
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

    expect(session()->get(EnvironmentAdminAuth::SESSION_KEY))->toBeNull();
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

    expect(session()->get(EnvironmentAdminAuth::SESSION_KEY))->toBeNull();

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

    expect(session()->get(EnvironmentAdminAuth::SESSION_KEY))->toBeNull();
});

/**
 * The handoff is the SaaS route into the same console. It re-resolved the membership on
 * redemption — catching a revoked membership or a downgraded role — but never asked
 * whether the ACCOUNT behind that member was still active. Every other resolve path
 * does.
 */
it('refuses a handoff for a member whose account has been suspended', function (): void {
    $result = adminDoorSetup();
    serveOnTestHost($result->environment);

    $subjectId = (string) $result->member->refresh()->subject_id;
    $token = app(EnvironmentAdminHandoff::class)->mint($subjectId, $result->environment->id);

    // Suspended between the mint and the redemption — the tab that sat open.
    Account::query()->whereKey($result->account->id)->update(['status' => AccountStatus::Suspended]);

    $this->get("/admin/handoff?token={$token}")->assertRedirect(route('admin.login'));

    expect(session(EnvironmentAdminAuth::SESSION_KEY))->toBeNull();
});

it('refuses a handoff when the policy mandates SSO', function (): void {
    $result = adminDoorSetup();
    serveOnTestHost($result->environment);

    $subjectId = (string) $result->member->refresh()->subject_id;
    $token = app(EnvironmentAdminHandoff::class)->mint($subjectId, $result->environment->id);

    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required)),
    );

    $this->get("/admin/handoff?token={$token}")->assertRedirect(route('admin.login'));

    expect(session(EnvironmentAdminAuth::SESSION_KEY))->toBeNull();
});
