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
 * The console page lists every user's handsets and the errors their pushes produced —
 * who is enrolled, on what, and which devices are currently failing. That is admin-only
 * data, and route middleware does not gate it: `platform.auth` only proves a session
 * exists and `console.feature` only checks a flag.
 */
function signInAs(MembershipRole $role): string
{
    config()->set('id-devices.enabled', true);

    $subject = app(Subjects::class)->create(
        Str::lower($role->value).'@acme.test',
        'Console User',
        'supersecret123',
    );
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-devices-'.Str::lower($role->value)));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    return $subject->id;
}

function consoleDevice(string $subjectId, string $name): void
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
}

it('refuses an ordinary member', function (): void {
    $subjectId = signInAs(MembershipRole::Member);
    consoleDevice($subjectId, 'Secret Handset');

    Volt::test('devices.index')->assertForbidden();
});

it('lets an admin read the inventory', function (): void {
    $subjectId = signInAs(MembershipRole::Admin);
    consoleDevice($subjectId, 'Admin Handset');

    Volt::test('devices.index')
        ->assertOk()
        ->assertSee('Admin Handset');
});

it('re-checks authorization on every render, not only on mount', function (): void {
    $subjectId = signInAs(MembershipRole::Admin);
    consoleDevice($subjectId, 'Admin Handset');

    $page = Volt::test('devices.index')->assertOk();

    // Demoted mid-session: the next hydration must not keep serving the inventory from
    // the authorization decision made at mount.
    app(CurrentUser::class)->set(
        app(Subjects::class)->find($subjectId),
        app(CurrentUser::class)->session(),
        app(CurrentUser::class)->organization(),
        MembershipRole::Member,
    );

    $page->call('$refresh')->assertForbidden();
});

it('is a fleet inventory, not an enrolment surface', function (): void {
    signInAs(MembershipRole::Admin);

    // Enrolment is personal and lives under My account, where every user can reach it.
    // The admin page points there instead of duplicating the code — and it does NOT
    // provision the OAuth client as a side effect of an inventory read.
    Volt::test('devices.index')
        ->assertOk()
        ->assertSee('My account')
        ->assertDontSee('com.cboxid.authenticator://connect', escape: false);

    expect(AuthenticatorClient::find())->toBeNull();
});
