<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\DeviceAuthorization;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\GrantPollStatus;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\DeviceCode;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

function signedInFor(): string
{
    $subject = app(Subjects::class)->create('dev@acme.test', 'Dev User', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-dev'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    // The request session too, not only CurrentUser: this page is a set of REQUESTS now,
    // and one that arrives without a session is redirected to the door — where "nothing was
    // approved" is true for entirely the wrong reason.
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    return $subject->id;
}

function deviceClient(): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        // Both scopes it is used to request below: a device-flow request naming a scope
        // the client is not registered for is refused outright rather than downscoped,
        // because there is no browser in the loop to notice a smaller grant.
        name: 'TV App', type: ClientType::Confidential, grantTypes: ['urn:ietf:params:oauth:grant-type:device_code'], scopes: ['openid', 'email'],
    ))->client;
}

it('shows the requesting app and scopes, then approves so the device can get a token', function () {
    $userId = signedInFor();
    $result = app(DeviceAuthorization::class)->request(deviceClient(), ['openid', 'email']);

    // Step 1: the code resolves to the app + scopes, shown before any approval.
    lookUpDeviceCode($result->userCode)->assertRedirect(route('device'));

    $client = deviceScreen()['client'];

    expect($client['name'])->toBe('TV App')
        ->and(collect($client['scopes'])->pluck('scope')->all())->toBe(['openid', 'email'])
        // The HUMAN label, which is what the person actually reads before approving.
        ->and(collect($client['scopes'])->pluck('label'))->toContain('Your email address');

    // Step 2: approve.
    approveDevice()->assertRedirect(route('device'));

    expect(flashed('deviceOutcome'))->toBe('approved');

    // Skip the poll interval, then the device redeems its token bound to the user.
    DeviceCode::query()->update(['last_polled_at' => now()->subMinute()]);
    $grant = app(DeviceAuthorization::class)->redeem(
        DeviceCode::query()->value('client_id'),
        $result->deviceCode,
    );

    expect($grant->userId)->toBe($userId);
});

it('reports an invalid or unknown code without moving to consent', function () {
    signedInFor();

    lookUpDeviceCode('ZZZZ-ZZZZ')
        ->assertSessionHasErrors(['userCode' => 'That code is invalid or has expired. Check the code on your device and try again.']);

    // …and nothing was consented to, so the screen is still the form.
    expect(deviceScreen()['client'])->toBeNull();
});

it('denies a device', function () {
    signedInFor();
    $result = app(DeviceAuthorization::class)->request(deviceClient(), ['openid']);

    lookUpDeviceCode($result->userCode);
    denyDevice();

    expect(flashed('deviceOutcome'))->toBe('denied');

    expect(DeviceCode::query()->value('status'))->toBe(GrantPollStatus::Denied);
});

it('upper-cases the code from the verification_uri_complete link', function () {
    signedInFor();
    $result = app(DeviceAuthorization::class)->request(deviceClient(), ['openid']);

    // Typed either way, resolved the same: the code is upper-case on the device's screen,
    // and a phone keyboard sends whatever it decided.
    test()->get(route('device', ['user_code' => strtolower($result->userCode)]))
        ->assertRedirect(route('device'));

    expect(deviceScreen()['client']['name'])->toBe('TV App');
});

it('rate-limits repeated invalid code lookups (anti-guessing)', function () {
    signedInFor();

    for ($i = 0; $i < 10; $i++) {
        lookUpDeviceCode('ZZZZ-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
    }

    $refusal = lookUpDeviceCode('AAAA-AAAA')->assertSessionHasErrors('userCode');

    expect(session()->get('errors')->first('userCode'))->toContain('Too many attempts')
        ->and(deviceScreen()['client'])->toBeNull();
});

/**
 * THE BROWSER CANNOT NAME THE CODE IT IS APPROVING.
 *
 * Under Volt the resolved code, the app name and the scopes were component properties, and
 * `#[Locked]` was what stopped a swapped `user_code` approving a DIFFERENT request than the
 * one consented to. There are no properties now: the consented code lives in the session
 * and the approve endpoint reads it from there and from nowhere else, so a body that names
 * a code is a body nothing looks at.
 *
 * Asserted by trying: approve with a real, pending code in the request and nothing in the
 * session, and it must refuse rather than approve the request that code belongs to.
 */
it('never approves a code the session did not consent to', function () {
    signedInFor();
    $result = app(DeviceAuthorization::class)->request(deviceClient(), ['openid']);

    inertiaRequest(fn (): TestResponse => test()->from(route('device'))
        ->post(route('device.approve'), ['userCode' => $result->userCode, 'confirmedCode' => $result->userCode]))
        ->assertNotFound();

    expect(DeviceCode::query()->value('status'))->not->toBe(GrantPollStatus::Approved);
})->group('security');

it('does not ask the browser to autofill a one-time code over the device code', function () {
    // MEASURED IN A BROWSER, not theorised: the field carried
    // autocomplete="one-time-code", which means "a code delivered out of band TO THIS
    // DEVICE" — so Safari and every password manager offered the last SMS OTP they had
    // seen and REPLACED the code the verification link had just prefilled. The form then
    // submitted six digits the user never saw and the page said "that code is invalid or
    // has expired", accusing a device that had done nothing wrong.
    //
    // A device-authorization user_code travels the other way: shown on another screen,
    // typed in here. It must never invite OTP autofill.
    signedInFor();

    /*
     * READ FROM THE PAGE'S SOURCE. The field is drawn by the bundle, so no request-level
     * response carries it any more — and what is being asserted is a property of the
     * component itself, which is where it can be stated exactly once.
     */
    $source = (string) file_get_contents(resource_path('js/pages/device.tsx'));

    expect($source)->not->toContain('autoComplete="one-time-code"');
    expect($source)->toContain('autoComplete="off"');
});

it('goes straight to the approval screen when the link carries the code', function () {
    // The other half of the same story: the link was doing its job, which is why the
    // wrong value in the field was so hard to explain.
    //
    // And having carried the code, the page used to stop and show it in a field with a
    // Continue button. RFC 8628 §3.3.1 defines `verification_uri_complete` so the person
    // does not have to type or confirm the code — following the link IS that step, and
    // being asked to press Continue on a form they did not fill in reads, on a phone, as
    // something having gone wrong.
    signedInFor();
    $result = app(DeviceAuthorization::class)->request(deviceClient(), ['openid']);

    test()->get(route('device', ['user_code' => $result->userCode]))
        ->assertRedirect(route('device'));

    // Resolved, not approved: what they came to read is which app is asking.
    expect(deviceScreen()['client']['name'])->toBe('TV App')
        ->and(flashed('deviceOutcome'))->toBeNull()
        ->and(DeviceCode::query()->value('status'))->not->toBe(GrantPollStatus::Approved);
});

/**
 * A dead link is not the same event as a mistyped code.
 *
 * Somebody following `verification_uri_complete` after the code expired never saw the
 * code, so "check the code on your device and try again" is advice about something they
 * cannot check. They get the form, empty, and a sentence that describes what happened.
 */
it('offers the form when the link carries a code that no longer exists', function () {
    signedInFor();

    test()->get(route('device', ['user_code' => 'ZZZZ-ZZZZ']))->assertRedirect(route('device'));

    expect(flashed('deviceError'))->toContain('expired or already finished');

    // …and the form, empty — not a field pre-filled with a code that means nothing.
    expect(deviceScreen()['client'])->toBeNull();
});

/**
 * @group security
 *
 * THE WHOLE CLI CHAIN, which no single component's tests can see.
 *
 * A person following `verification_uri_complete` from a terminal is almost never already
 * signed in on their phone. So the link goes to `/device?user_code=…`, auth bounces them
 * to `/login` and stashes where they were going, and after signing in they have to land
 * back on the device page WITH the code still attached — otherwise they are asked to type
 * a code they never saw, which is the failure the complete-URL exists to prevent.
 *
 * Three components own one link between them: the auth middleware stashes it, IntendedUrl
 * decides whether this plane may serve it, and the device page resolves it. Each is
 * covered on its own and the chain was covered nowhere, so any of the three could drop
 * the query string and every suite would stay green.
 */
it('returns a person to the device approval after they sign in to reach it', function () {
    $subject = app(Subjects::class)->create('cli@acme.test', 'CLI User', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme CLI', 'acme-cli'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $result = app(DeviceAuthorization::class)->request(deviceClient(), ['openid']);
    $complete = '/device?user_code='.$result->userCode;

    // Signed out, following the link from the terminal.
    $this->get($complete)->assertRedirect(route('login'));

    // The whole URL was kept, not just the path: without the query the person arrives at
    // an empty form and is told to check a code they were never shown.
    expect(session()->get('url.intended'))->toContain('user_code='.$result->userCode);

    // And the sign-in page says why they are there. "Access your organization's identity
    // console" is the wrong sentence for somebody who followed a link printed by a
    // terminal — a small dissonance at exactly the moment they are judging whether the
    // link is legitimate.
    // The PROP the page draws that sentence from. "Access your organization's identity
    // console" is the wrong greeting for somebody who followed a link printed by a
    // terminal — a small dissonance at exactly the moment they are judging whether the
    // link is legitimate.
    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('purpose', 'Sign in to approve the device that is waiting.'));

    test()->from(route('login'))->post(route('login.attempt'), ['email' => 'cli@acme.test', 'password' => 'a-strong-unbreached-passphrase'])
        ->assertRedirect($complete);

    // And landing there resolves the code rather than showing it in a field.
    test()->get($complete)->assertRedirect(route('device'));

    expect(deviceScreen()['client']['name'])->toBe('TV App');
})->group('security');
