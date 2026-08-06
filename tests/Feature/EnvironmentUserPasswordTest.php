<?php

declare(strict_types=1);

use App\Mail\AdminAssignedPasswordMail;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// The console validates against the breached-password list; keep the suite offline.
beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** Provision an environment, pin an env-admin session, and return a target subject id. */
function pwUserSetup(): string
{
    platformRootEnvironment();
    $r = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($r->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
    actAsEnvironmentAdmin($r->member, $r->environment->id);

    return app(Subjects::class)->create('dana@acme.example', 'Dana', 'the-original-passphrase')->id;
}

it('sets a temporary password and reveals it once without putting it in the snapshot', function (): void {
    $userId = pwUserSetup();

    $component = Volt::test('environment.users.show', ['user' => $userId])
        ->set('pwPassword', 'a-handed-over-temporary-passphrase')
        ->set('pwReason', 'Locked out after losing their phone')
        ->set('pwMode', 'temporary')
        ->set('pwDelivery', 'reveal')
        ->call('setPassword')
        ->assertHasNoErrors();

    // Revealed through the render...
    $component->assertSee('a-handed-over-temporary-passphrase');

    // ...and the credential really changed, with a standing change requirement.
    $subjects = app(Subjects::class);
    expect($subjects->verifyPassword($userId, 'a-handed-over-temporary-passphrase'))->toBeTrue()
        ->and($subjects->verifyPassword($userId, 'the-original-passphrase'))->toBeFalse()
        ->and(app(AdminPasswords::class)->requiresChange($userId))->toBeTrue();
});

it('emails the password instead when the admin chooses that delivery', function (): void {
    $userId = pwUserSetup();
    Mail::fake();

    Volt::test('environment.users.show', ['user' => $userId])
        ->set('pwPassword', 'an-emailed-temporary-passphrase')
        ->set('pwReason', 'Requested by their manager')
        ->set('pwDelivery', 'email')
        ->call('setPassword')
        ->assertHasNoErrors()
        // The credential must NOT also be revealed on screen when it was emailed.
        ->assertDontSee('an-emailed-temporary-passphrase');

    Mail::assertSent(AdminAssignedPasswordMail::class);
});

it('honours the chosen blast radius for existing sessions', function (): void {
    $userId = pwUserSetup();
    $sessions = app(SessionManager::class);

    $keep = $sessions->start($userId, null, ['pwd'])->id;
    Volt::test('environment.users.show', ['user' => $userId])
        ->set('pwPassword', 'a-quiet-replacement-passphrase')
        ->set('pwReason', 'Routine rotation')
        ->set('pwRevoke', 'nothing')
        ->call('setPassword')
        ->assertHasNoErrors();
    expect($sessions->active($keep))->not->toBeNull();

    $cut = $sessions->start($userId, null, ['pwd'])->id;
    Volt::test('environment.users.show', ['user' => $userId])
        ->set('pwPassword', 'a-disruptive-replacement-passphrase')
        ->set('pwReason', 'Suspected compromise')
        ->set('pwRevoke', 'sessions_and_tokens')
        ->call('setPassword')
        ->assertHasNoErrors();
    expect($sessions->active($cut))->toBeNull();
});

it('requires a reason and a strong password', function (): void {
    $userId = pwUserSetup();

    Volt::test('environment.users.show', ['user' => $userId])
        ->set('pwPassword', 'short')
        ->set('pwReason', '')
        ->call('setPassword')
        ->assertHasErrors(['pwPassword', 'pwReason']);

    // Nothing was written on a rejected attempt.
    expect(app(Subjects::class)->verifyPassword($userId, 'the-original-passphrase'))->toBeTrue();
});

// The capability gate: reaching an environment is not the same as administering it.
it('refuses a member without the environment-admin capability', function (): void {
    platformRootEnvironment();
    $r = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner2@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($r->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));

    $members = app(Memberships::class);
    $viewer = $members->invite($r->account->id, 'viewer@acme.example', MembershipRole::Viewer);
    $members->activate($viewer->id, 'a-strong-unbreached-passphrase');

    actAsEnvironmentAdmin($viewer, $r->environment->id);

    $userId = app(Subjects::class)->create('target@acme.example', 'Target', 'the-original-passphrase')->id;

    // boot() refuses before any action runs — a viewer can never set a password.
    Volt::test('environment.users.show', ['user' => $userId])->assertForbidden();

    expect(app(Subjects::class)->verifyPassword($userId, 'the-original-passphrase'))->toBeTrue();
});
