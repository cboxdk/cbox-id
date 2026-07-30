<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
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

it('shows an enrolment code once an authenticator client exists', function (): void {
    signInAs(MembershipRole::Admin);

    // Nothing to connect to yet, so a code would be a dead end.
    Volt::test('devices.index')->assertOk()->assertSee('No authenticator app is set up');

    $this->artisan('cbox-id:devices:client')->assertSuccessful();

    Volt::test('devices.index')
        ->assertOk()
        ->assertSee('Add a phone')
        // The scheme the app actually registers, so a scan resolves to it.
        ->assertSee('com.cboxid.authenticator://connect', escape: false)
        ->assertSee('<svg', escape: false);
});

it('puts nothing secret in the enrolment code', function (): void {
    signInAs(MembershipRole::Admin);
    $this->artisan('cbox-id:devices:client')->assertSuccessful();

    $uri = Volt::test('devices.index')->instance()->enrolmentUri();

    // Host only. A code carrying a token would have to be short-lived and single-use,
    // and could not be left on a screen — which is the whole point of showing one.
    expect($uri)->toContain('connect?host=')
        ->and($uri)->not->toContain('token')
        ->and($uri)->not->toContain('cid_');
});
