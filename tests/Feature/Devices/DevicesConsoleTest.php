<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
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

    // AND THE SESSION KEY THE CONSOLE'S GUARD READS: the page is a REQUEST now, and without
    // this every one of these tests would be asserting against a redirect to sign-in.
    session([PlatformAuth::SESSION_KEY => $session->id]);

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

    test()->get(route('devices.index'))->assertForbidden();
});

it('lets an admin read the inventory', function (): void {
    $subjectId = signInAs(MembershipRole::Admin);
    consoleDevice($subjectId, 'Admin Handset');

    expect(collect((array) test()->get(route('devices.index'))->assertOk()->inertiaProps('devices'))->pluck('name'))
        ->toContain('Admin Handset');
});

it('re-checks authorization on every request, not only on the first', function (): void {
    $subjectId = signInAs(MembershipRole::Admin);
    consoleDevice($subjectId, 'Admin Handset');

    test()->get(route('devices.index'))->assertOk();

    /*
     * DEMOTED MID-SESSION. Under Livewire this was the `boot()`-versus-`mount()` distinction
     * — a page already open kept re-rendering from the decision made at mount. A ported page
     * has no such state: every render is its own request through the full stack, so the
     * property is free. It is asserted anyway, because it is the property that matters and
     * the mechanism that guarantees it has just changed underneath it.
     */
    app(Memberships::class)->changeRole(
        (string) app(CurrentUser::class)->organizationId(),
        $subjectId,
        MembershipRole::Member,
    );

    app(CurrentUser::class)->set(
        app(Subjects::class)->find($subjectId),
        app(CurrentUser::class)->session(),
        app(CurrentUser::class)->organization(),
        MembershipRole::Member,
    );

    test()->get(route('devices.index'))->assertForbidden();
});

it('is a fleet inventory, not an enrolment surface', function (): void {
    signInAs(MembershipRole::Admin);

    // Enrolment is personal and lives under My account, where every user can reach it.
    // The admin page points there instead of duplicating the code — and it does NOT
    // provision the OAuth client as a side effect of an inventory read.
    // Enrolment is personal and lives under My account, where every user can reach it: the
    // admin page POINTS there rather than duplicating the flow — and it does not provision
    // the OAuth client as a side effect of an inventory read.
    $page = test()->get(route('devices.index'))->assertOk();

    expect($page->inertiaProps('personalPage'))->toBe(route('devices.mine'))
        ->and(AuthenticatorClient::find())->toBeNull();
});
