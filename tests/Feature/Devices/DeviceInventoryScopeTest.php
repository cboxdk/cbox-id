<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
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

uses(RefreshDatabase::class);

/**
 * The device inventory is gated on an ORGANIZATION-level role and read the whole
 * environment.
 *
 * Two organizations routinely share one environment — that is the ordinary
 * multi-tenant shape — and a `Device` is keyed by subject, not by organization. So an
 * admin of one tenant was handed every other tenant's handset names, models, OS
 * versions, status and health, plus the last twenty-five push records including their
 * error text. The page's own docblock calls that "precisely the reconnaissance an
 * attacker wants", which was true of the page itself.
 */
it('shows an admin only their own organization devices', function (): void {
    config()->set('id-devices.enabled', true);

    $mine = app(Subjects::class)->create('admin@acme.test', 'Admin', 'a-strong-unbreached-passphrase');
    $theirs = app(Subjects::class)->create('someone@other.test', 'Someone Else', 'a-strong-unbreached-passphrase');

    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-inventory'));
    $other = app(Organizations::class)->create(new NewOrganization('Other', 'other-inventory'));

    app(Memberships::class)->add($acme->id, $mine->id, MembershipRole::Admin);
    app(Memberships::class)->add($other->id, $theirs->id, MembershipRole::Admin);

    inventoryDevice($mine->id, 'My Own Handset');
    inventoryDevice($theirs->id, 'Another Tenant Handset');

    $session = app(SessionManager::class)->start($mine->id, $acme->id, ['pwd']);
    app(CurrentUser::class)->set($mine, $session, $acme, MembershipRole::Admin);

    // AND THE SESSION KEY THE CONSOLE'S GUARD READS. The page is reached by a REQUEST now,
    // and without this it answers a redirect to sign-in — which "does not show the other
    // tenant's handset" passes happily.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $names = collect((array) test()->get(route('devices.index'))->assertOk()->inertiaProps('devices'))
        ->pluck('name');

    // The positive control: without it, a page that shows nothing at all would pass.
    expect($names)->toContain('My Own Handset')
        ->and($names)->not->toContain('Another Tenant Handset');
});

function inventoryDevice(string $subjectId, string $name): void
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
