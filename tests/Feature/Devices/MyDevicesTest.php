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

/**
 * THIS TEST USED TO ASSERT THE OPPOSITE, and it was green while being wrong.
 *
 * It was called "puts nothing secret in the enrolment code" and reasoned: "Host only. A
 * code carrying a token would have to be short-lived and single-use, and could not be left
 * on a screen." Every clause of that stopped being true when the code started carrying an
 * identity — the URI is `…?host=…&t=<JWT>` and the JWT is a subject-bound bearer
 * credential. The assertion survived the change because it greps for the literal string
 * `token` and the query parameter is named `t`.
 *
 * So the property is restated as what the code actually is, and the protections are named
 * where they live: the token is short-lived, single-use and bound to one subject, and all
 * three are proved in EnrolmentCodeTest. What must NOT be in the URI is a durable
 * credential — a client secret or an access token — because those would survive being
 * screenshotted.
 */
it('carries a subject-bound single-use token and no durable credential', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-secret@acme.test');

    $uri = Volt::test('devices.mine')->instance()->enrolmentUri();

    expect($uri)->toContain('connect?host=')
        // The credential it DOES carry, stated rather than denied.
        ->and($uri)->toContain('&t=')
        // …and the ones it must never carry. A client id or secret in a QR code on a
        // screen is a credential with no expiry standing in a photograph.
        ->and($uri)->not->toContain('cid_')
        ->and($uri)->not->toContain('client_secret');
});

/**
 * The raw URI is a credential, so it is a tap target and not printed text.
 *
 * The card used to render it as a line of selectable monospace under the QR — a 400-plus
 * character string that overflowed the panel on a laptop, and worse, put the same secret
 * the page warns you not to screenshot into text that copies, pastes into a support ticket
 * and shows up in a screen share. The href still carries it, unavoidably and exactly as
 * the QR does; what changed is that nothing renders it for a shoulder to read.
 */
it('offers the enrolment link as a control rather than as printed text', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-linktext@acme.test');

    $component = Volt::test('devices.mine');
    $uri = $component->instance()->enrolmentUri();
    $html = $component->assertOk()->html();

    expect($html)->toContain('href="'.e($uri).'"')
        // Never as a text node — the old `<p class="mono">{{ $uri }}</p>`.
        ->and((bool) preg_match('/>\s*'.preg_quote(e($uri), '/').'/', $html))->toBeFalse();
});

/**
 * Opening this page ON the phone being enrolled is the most natural thing to do, and it
 * used to be a dead end: the card rendered a QR at every width and offered nothing else,
 * and you cannot scan the screen you are holding.
 */
it('gives a phone something to tap and does not ask it to scan itself', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-mobile@acme.test');

    $html = Volt::test('devices.mine')->assertOk()->html();

    // The tap target is the primary action below the `sm` breakpoint…
    expect($html)->toContain('Open the Cbox ID app')
        // …and the QR is withheld there rather than rendered uselessly. Asserted on the
        // class that hides it, because the SVG is present in the DOM either way.
        ->and($html)->toContain('hidden sm:block');
});

/**
 * A self-hosted deployment ships its own build under its own bundle id, so a hardcoded
 * store link would send their people to the wrong app — a dead end they cannot diagnose.
 * Unset means the line is absent, not empty.
 */
it('links to the app store only when the deployment names one', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-store@acme.test');

    expect(Volt::test('devices.mine')->assertOk()->html())->not->toContain('Get the app');

    config()->set('id-devices.app_store_url', 'https://apps.example.test/cbox-id');

    $html = Volt::test('devices.mine')->assertOk()->html();

    expect($html)->toContain('Get the app')
        ->and($html)->toContain('https://apps.example.test/cbox-id');
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
