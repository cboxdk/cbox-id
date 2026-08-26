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
    // The request session too, not only CurrentUser: this page is a request now, and one
    // arriving without a session is redirected to the door — where "no device was removed"
    // is true for entirely the wrong reason.
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
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

    $enrolment = myDevices()['enrolment'];

    expect($enrolment)->not->toBeNull()
        // The scheme the app actually registers, so a scan resolves to it.
        ->and($enrolment['uri'])->toStartWith('com.cboxid.authenticator://connect')
        // …and the QR encodes THAT code, as an image the page can draw without injecting
        // markup into itself.
        ->and($enrolment['qr'])->toStartWith('data:image/svg+xml;base64,');
});

it('provisions the authenticator client itself on first view — no command required', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-provision@acme.test');

    expect(AuthenticatorClient::find())->toBeNull();

    myDevices();

    // Enabling the feature is a config change; nothing asks anyone for a CLI.
    expect(AuthenticatorClient::find())->not->toBeNull();
});

it('never mints a second client on later views', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-idempotent@acme.test');

    myDevices();
    $first = AuthenticatorClient::find()?->client_id;

    myDevices();

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

    $uri = myDevices()['enrolment']['uri'];

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
    /*
     * ASKED OF THE PAGE'S SOURCE, which is where the decision now lives. The URI reaches
     * the bundle as a prop either way — that is unavoidable, and the QR carries it too —
     * so what can be lost is the page rendering it as a TEXT NODE, and that is a property
     * of this component and of nothing else.
     */
    signInMemberAs(MembershipRole::Member, 'member-linktext@acme.test');

    $source = (string) file_get_contents(base_path('modules/devices/resources/js/pages/mine.tsx'));

    // The href, and only the href.
    expect($source)->toContain('href={enrolment.uri}');

    // Never `{enrolment.uri}` on its own between tags — the old `<p class="mono">{{ $uri }}</p>`.
    expect((bool) preg_match('/>\s*\{enrolment\.uri\}/', $source))->toBeFalse();
});

/**
 * Opening this page ON the phone being enrolled is the most natural thing to do, and it
 * used to be a dead end: the card rendered a QR at every width and offered nothing else,
 * and you cannot scan the screen you are holding.
 */
it('gives a phone something to tap and does not ask it to scan itself', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-mobile@acme.test');

    // Both halves are drawn by the component, so both are asked of it — and that they are
    // actually DRAWN at a phone width is held in tests/Browser, which is the only place
    // that can see a breakpoint.
    $source = (string) file_get_contents(base_path('modules/devices/resources/js/pages/mine.tsx'));

    // The tap target is the primary action below the `sm` breakpoint…
    expect($source)->toContain('Open the Cbox ID app');

    // …and the QR is withheld there rather than rendered uselessly.
    expect($source)->toContain('hidden sm:block');
});

/**
 * A self-hosted deployment ships its own build under its own bundle id, so a hardcoded
 * store link would send their people to the wrong app — a dead end they cannot diagnose.
 * Unset means the line is absent, not empty.
 */
it('links to the app store only when the deployment names one', function (): void {
    signInMemberAs(MembershipRole::Member, 'member-store@acme.test');

    // NULL, not an empty string: the page draws the line from this prop and from nothing
    // else, so a null is the absence and an empty string would be a link to nowhere.
    expect(myDevices()['appStoreUrl'])->toBeNull();

    config()->set('id-devices.app_store_url', 'https://apps.example.test/cbox-id');

    expect(myDevices()['appStoreUrl'])->toBe('https://apps.example.test/cbox-id');
});

it('shows only the caller own devices', function (): void {
    $other = signInMemberAs(MembershipRole::Member, 'other-owner@acme.test');
    myDevice($other, 'Someone Elses Phone');

    $mine = signInMemberAs(MembershipRole::Member, 'me@acme.test');
    myDevice($mine, 'My Own Phone');

    $names = collect(myDevices()['devices'])->pluck('name');

    expect($names)->toContain('My Own Phone')
        ->and($names)->not->toContain('Someone Elses Phone');
});

it('removes an own device', function (): void {
    $mine = signInMemberAs(MembershipRole::Member, 'remover@acme.test');
    $device = myDevice($mine, 'Old Phone');

    removeOwnDevice($device->id)->assertSessionHasNoErrors();

    expect(Device::query()->whereKey($device->id)->exists())->toBeFalse();
});

it('cannot remove another user device', function (): void {
    $other = signInMemberAs(MembershipRole::Member, 'victim@acme.test');
    $device = myDevice($other, 'Victim Phone');

    signInMemberAs(MembershipRole::Member, 'attacker@acme.test');

    // 404 — someone else's device is treated exactly like a missing one, and stays put.
    removeOwnDevice($device->id)->assertNotFound();

    expect(Device::query()->whereKey($device->id)->exists())->toBeTrue();
});
