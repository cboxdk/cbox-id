<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\LoginAttempts;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * The admin door's MFA step must ask every question the password step asks.
 *
 * It did not. `$step` and `$pendingMemberId` were plain public Livewire properties —
 * client state — and `verifyMfa()` gated on nothing but "the pending id is not empty".
 * So a crafted Livewire update could put the component straight into the MFA step for
 * any member id, and a member who submitted their OWN valid TOTP code received a full
 * environment-admin session having never presented a password and without any of the
 * four authorization checks the password path enforces:
 *
 *   - the role can manage environments,
 *   - this environment is in their assignment,
 *   - the account is not locked out,
 *   - the credential is admitted (SSO mandate, temporary-password expiry) and is not
 *     one an administrator issued and still knows.
 *
 * Reachable wherever the form renders — that is the single-tenant / self-hosted shape,
 * which is what `.env.example` ships. Both layers are pinned below: the properties are
 * `#[Locked]`, and the gate is re-run before any session exists.
 *
 * The gate tests reach the MFA step THROUGH THE PASSWORD STEP and then break one
 * condition, which is also the shape of the second, still-live attack: a TOCTOU. The
 * member is admissible when they type their password and is not when they type their
 * code — suspended, demoted, un-assigned, locked out in between — and the pending id
 * sat on the server across the gap. A test that merely called `verifyMfa()` on a
 * component with no pending id proves nothing about this block: it is refused three
 * checks earlier, by `$this->pendingMemberId === ''`.
 */
function adminDoorEnvironment(): array
{
    platformRootEnvironment();

    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);

    // The door authenticates against the environment the HOST resolves to; pin it the
    // way the middleware would, so the component under test sees a tenant environment
    // rather than the platform root.
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));

    return [$result->member, $result->environment->id];
}

/**
 * The admin door, genuinely PENDING: password accepted, second factor outstanding.
 *
 * `$pendingMemberId` is server-set and `#[Locked]`, so the only honest way to put a
 * component into this state is to pass the password step — which is the point. The
 * caller then breaks exactly one of the gate's conditions and submits a VALID code, so
 * the refusal can only have come from the block under test.
 *
 * @return array{0: Testable, 1: AccountMember, 2: string, 3: string} component, member, environment id, TOTP secret
 */
function adminDoorAtMfaStep(): array
{
    [$member, $environmentId] = adminDoorEnvironment();

    $mfa = app(AccountMemberMfa::class);
    $totp = app(TotpAuthenticator::class);
    $enrollment = $mfa->enrollTotp($member->id, $member->email);
    $mfa->confirmTotp($member->id, $totp->codeAt($enrollment->secret, time()));

    $component = Volt::test('admin.login')
        ->set('email', 'owner@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('authenticate')
        ->assertHasNoErrors();

    // The password step really did hand off — otherwise everything below would be
    // asserting against a component still sitting on the password form.
    expect($component->get('step'))->toBe('mfa')
        ->and($component->get('pendingMemberId'))->toBe($member->id);

    return [$component, $member, $environmentId, $enrollment->secret];
}

/**
 * Submit a VALID code and assert the door refused anyway — with no session, and with
 * the pending state torn down so the refused attempt cannot simply be repeated.
 */
function expectMfaStepRefused(Testable $component, string $secret): void
{
    $component
        ->set('code', app(TotpAuthenticator::class)->codeAt($secret, time() + 30))
        ->call('verifyMfa')
        ->assertHasErrors('email');

    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse()
        ->and(session()->has(EnvironmentAdminAuth::ENV_KEY))->toBeFalse()
        ->and($component->get('step'))->toBe('password')
        ->and($component->get('pendingMemberId'))->toBe('');
}

it('locks the step and pending-member properties against the client', function (): void {
    adminDoorEnvironment();

    // Livewire refuses to hydrate a locked property from the wire. Without this, the
    // update below would put the component into the MFA step for an arbitrary member.
    Volt::test('admin.login')
        ->set('step', 'mfa')
        ->assertStatus(500);
})->throws(Exception::class);

/**
 * The other half of the same layer, and the half that carries the member id. Locking
 * `$step` alone is worth nothing: the component reads the pending id, not the step, so
 * an unlocked `$pendingMemberId` lets a crafted update name ANY member — including one
 * who never authenticated here — and `verifyMfa()` proceeds against their factor.
 */
it('locks the pending-member id against the client', function (): void {
    [$member] = adminDoorEnvironment();

    Volt::test('admin.login')
        ->set('pendingMemberId', $member->id)
        ->assertStatus(500);
})->throws(Exception::class);

it('refuses the MFA step to a member whose role can no longer manage environments', function (): void {
    [$component, $member, , $secret] = adminDoorAtMfaStep();

    // Demoted while they sat on the code prompt. Billing is a real, authenticated
    // account identity with a real second factor, and no business administering an
    // environment.
    app(AccountMembers::class)->setRole($member->id, AccountRole::Billing);

    expect(AccountRole::Billing->canManageEnvironments())->toBeFalse();

    expectMfaStepRefused($component, $secret);
});

it('refuses the MFA step once this environment leaves the member assignment', function (): void {
    [$component, $member, , $secret] = adminDoorAtMfaStep();

    // Developer manages environments AND supports scoping — so the role check passes
    // and the ASSIGNMENT check is the only thing left to refuse. Revoked mid-flight.
    $members = app(AccountMembers::class);
    $members->setRole($member->id, AccountRole::Developer);
    $members->setEnvironmentAccess($member->id, false, []);

    expect($members->accessibleEnvironmentIds($member->refresh()))->toBe([]);

    expectMfaStepRefused($component, $secret);
});

it('refuses the MFA step to a member locked out between the two steps', function (): void {
    [$component, $member, , $secret] = adminDoorAtMfaStep();

    $subjectId = (string) $member->refresh()->subject_id;

    app(PlatformRoot::class)->run(function () use ($subjectId): void {
        app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(lockoutThreshold: 3));

        // Guessed at from somewhere else while this browser held a pending id.
        foreach (range(1, 3) as $ignored) {
            app(LoginAttempts::class)->recordFailure($subjectId);
        }
    });

    expectMfaStepRefused($component, $secret);
});

it('refuses the MFA step once the environment mandates SSO', function (): void {
    [$component, , , $secret] = adminDoorAtMfaStep();

    app(PlatformRoot::class)->run(
        fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required)),
    );

    expectMfaStepRefused($component, $secret);
});

it('refuses the MFA step to a member who owes a password change', function (): void {
    [$component, $member, , $secret] = adminDoorAtMfaStep();

    $subjectId = (string) $member->refresh()->subject_id;

    // A password the administrator who issued it also knows, handed over after the
    // password step. The admin console has no page on which it can be replaced.
    app(PlatformRoot::class)->run(fn () => app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $subjectId,
        password: 'a-handed-over-temporary-passphrase',
        temporary: true,
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    )));

    expectMfaStepRefused($component, $secret);
});

it('establishes no session when the MFA step is called with no password step behind it', function (): void {
    adminDoorEnvironment();

    Volt::test('admin.login')
        ->set('code', '123456')
        ->call('verifyMfa');

    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse()
        ->and(session()->has(EnvironmentAdminAuth::ENV_KEY))->toBeFalse();
});

it('still admits an owner who passes both steps', function (): void {
    [$component, $member, $environmentId, $secret] = adminDoorAtMfaStep();

    // A LATER step than the one consumed on confirm: the factor refuses a replay
    // inside the still-valid skew window, which is correct and is why this is +30s.
    $component->set('code', app(TotpAuthenticator::class)->codeAt($secret, time() + 30))->call('verifyMfa');

    // Signed in, as that member, on that environment — asked through the resolver,
    // because an admin session is a live subject session plus the anchor and asserting
    // on either half alone would pass against a session the console refuses.
    expect(app(EnvironmentAdminAuth::class)->subjectId())->toBe($member->refresh()->subject_id)
        ->and(session()->get(EnvironmentAdminAuth::ENV_KEY))->toBe($environmentId);
});
