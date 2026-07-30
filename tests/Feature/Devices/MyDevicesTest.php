<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\AuthenticatorClient;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * The PERSONAL device surface: my phone, my enrolment code, my remove button. Approval
 * prompts and sign-in alerts are per-user features, so enrolment must be reachable by
 * every signed-in user — it sits beside passkeys and TOTP under My account, not behind
 * the org-admin gate the fleet inventory carries.
 */
function signInMemberAs(MembershipRole $role, string $email): string
{
    config()->set('id-devices.enabled', true);

    $subject = app(Subjects::class)->create($email, 'Device Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-mine-'.Str::lower(Str::random(6))));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    return $subject->id;
}

function myDevice(string $subjectId, string $name): Device
{
    $device = new Device;
    $device->fill([
        'subject_id' => $subjectId,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => $name,
        'status' => DeviceStatus::Active,
    ]);
    $device->save();

    return $device;
}

it('lets a plain member enrol — the code is personal, not org administration', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-enrol@acme.test');

    Volt::test('devices.mine')
        ->assertOk()
        ->assertSee('Add a phone')
        // The scheme the app actually registers, so a scan resolves to it.
        ->assertSee('com.cboxid.authenticator://connect', escape: false)
        ->assertSee('<svg', escape: false);
});

it('provisions the authenticator client itself on first view — no command required', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-provision@acme.test');

    expect(AuthenticatorClient::find())->toBeNull();

    Volt::test('devices.mine')->assertOk();

    // Enabling the feature is a config change; nothing asks anyone for a CLI.
    expect(AuthenticatorClient::find())->not->toBeNull();
});

it('never mints a second client on later views', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-idempotent@acme.test');

    Volt::test('devices.mine')->assertOk();
    $first = AuthenticatorClient::find()?->client_id;

    Volt::test('devices.mine')->assertOk();

    // A second client would silently strand every handset enrolled against the first.
    expect(Client::query()->where('name', AuthenticatorClient::NAME)->count())->toBe(1)
        ->and(AuthenticatorClient::find()?->client_id)->toBe($first);
});

it('puts nothing secret in the enrolment code', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-secret@acme.test');

    $uri = Volt::test('devices.mine')->instance()->enrolmentUri();

    // Host only. A code carrying a token would have to be short-lived and single-use,
    // and could not be left on a screen — which is the whole point of showing one.
    expect($uri)->toContain('connect?host=')
        ->and($uri)->not->toContain('token')
        ->and($uri)->not->toContain('cid_');
});

it('shows only the caller own devices', function (): void {
    $other = signInMemberAs(MembershipRole::Member, 'other-owner@acme.test');
    myDevice($other, 'Someone Elses Phone');

    $mine = signInMemberAs(MembershipRole::Member, 'me@acme.test');
    myDevice($mine, 'My Own Phone');

    Volt::test('devices.mine')
        ->assertOk()
        ->assertSee('My Own Phone')
        ->assertDontSee('Someone Elses Phone');
});

it('removes an own device', function (): void {
    $mine = signInMemberAs(MembershipRole::Member, 'remover@acme.test');
    $device = myDevice($mine, 'Old Phone');

    Volt::test('devices.mine')
        ->call('remove', $device->id)
        ->assertOk();

    expect(Device::query()->whereKey($device->id)->exists())->toBeFalse();
});

it('cannot remove another user device', function (): void {
    $other = signInMemberAs(MembershipRole::Member, 'victim@acme.test');
    $device = myDevice($other, 'Victim Phone');

    signInMemberAs(MembershipRole::Member, 'attacker@acme.test');

    Volt::test('devices.mine')->call('remove', $device->id);

    // Someone else's device is treated exactly like a missing one — and stays put.
    expect(Device::query()->whereKey($device->id)->exists())->toBeTrue();
});
