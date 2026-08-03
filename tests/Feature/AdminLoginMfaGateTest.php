<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

it('locks the step and pending-member properties against the client', function (): void {
    adminDoorEnvironment();

    // Livewire refuses to hydrate a locked property from the wire. Without this, the
    // update below would put the component into the MFA step for an arbitrary member.
    Volt::test('admin.login')
        ->set('step', 'mfa')
        ->assertStatus(500);
})->throws(Exception::class);

it('refuses the MFA step to a member whose role cannot manage environments', function (): void {
    [$owner] = adminDoorEnvironment();

    // A Billing member: a real, authenticated account identity with a real second
    // factor, and no business administering an environment.
    $billing = app(AccountMembers::class)->invite(
        $owner->account_id,
        'billing@acme.example',
        AccountRole::Billing,
    );

    expect($billing->role->canManageEnvironments())->toBeFalse();

    $component = Volt::test('admin.login');

    // Reach past the password step the way a crafted wire message would, then submit a
    // code. Even if the pending id could be planted, the gate must refuse.
    $component->set('code', '000000')->call('verifyMfa');

    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
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
    [$owner, $environmentId] = adminDoorEnvironment();

    $mfa = app(AccountMemberMfa::class);
    $totp = app(TotpAuthenticator::class);
    $enrollment = $mfa->enrollTotp($owner->id, $owner->email);
    $mfa->confirmTotp($owner->id, $totp->codeAt($enrollment->secret, time()));

    $component = Volt::test('admin.login')
        ->set('email', 'owner@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('authenticate');

    // The password step passed and handed off to MFA — the server, not the client, set
    // this. The fix must not have broken the legitimate path.
    expect($component->get('step'))->toBe('mfa');

    // A LATER step than the one consumed on confirm: the factor refuses a replay
    // inside the still-valid skew window, which is correct and is why this is +30s.
    $component->set('code', $totp->codeAt($enrollment->secret, time() + 30))->call('verifyMfa');

    // Signed in, as that member, on that environment — asked through the resolver,
    // because an admin session is a live subject session plus the anchor and asserting
    // on either half alone would pass against a session the console refuses.
    expect(app(EnvironmentAdminAuth::class)->subjectId())->toBe($owner->refresh()->subject_id)
        ->and(session()->get(EnvironmentAdminAuth::ENV_KEY))->toBe($environmentId);
});
