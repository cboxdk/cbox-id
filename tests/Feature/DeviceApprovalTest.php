<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
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
use Livewire\Volt\Volt;

function signedInFor(): string
{
    $subject = app(Subjects::class)->create('dev@acme.test', 'Dev User', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-dev'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
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
    $component = Volt::test('device')
        ->set('userCode', $result->userCode)
        ->call('lookup')
        ->assertSet('verified', true)
        ->assertSet('clientName', 'TV App')
        ->assertSet('scopes', ['openid', 'email'])
        ->assertSee('TV App')
        ->assertSee('Your email address'); // the human scope label

    // Step 2: approve.
    $component->call('approve')->assertSet('outcome', 'approved');

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

    Volt::test('device')
        ->set('userCode', 'ZZZZ-ZZZZ')
        ->call('lookup')
        ->assertSet('verified', false)
        ->assertSet('error', 'That code is invalid or has expired. Check the code on your device and try again.');
});

it('denies a device', function () {
    signedInFor();
    $result = app(DeviceAuthorization::class)->request(deviceClient(), ['openid']);

    Volt::test('device')
        ->set('userCode', $result->userCode)
        ->call('lookup')
        ->call('deny')
        ->assertSet('outcome', 'denied');

    expect(DeviceCode::query()->value('status'))->toBe(GrantPollStatus::Denied);
});

it('upper-cases the code from the verification_uri_complete link', function () {
    signedInFor();
    $result = app(DeviceAuthorization::class)->request(deviceClient(), ['openid']);

    // Typed either way, shown upper-case.
    Volt::test('device', ['user_code' => strtolower($result->userCode)])
        ->assertSet('userCode', strtoupper($result->userCode));
});

it('rate-limits repeated invalid code lookups (anti-guessing)', function () {
    signedInFor();
    $component = Volt::test('device');

    for ($i = 0; $i < 10; $i++) {
        $component->set('userCode', 'ZZZZ-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT))->call('lookup');
    }

    $component->set('userCode', 'AAAA-AAAA')->call('lookup');

    expect($component->get('verified'))->toBeFalse()
        ->and($component->get('error'))->toContain('Too many attempts');
});

it('does not let the browser forge the verified state (locked)', function () {
    signedInFor();

    Volt::test('device')->set('verified', true);
})->throws(Exception::class, 'Cannot update locked property');

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

    $html = Volt::test('device')->html();

    expect($html)->not->toContain('one-time-code')
        ->and($html)->toContain('autocomplete="off"');
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

    Volt::test('device', ['user_code' => $result->userCode])
        ->assertSet('verified', true)
        // Resolved, not approved: what they came to read is which app is asking.
        ->assertSet('clientName', 'TV App')
        ->assertSet('outcome', null)
        ->assertSee('Approve');
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

    Volt::test('device', ['user_code' => 'ZZZZ-ZZZZ'])
        ->assertSet('verified', false)
        ->assertSet('userCode', '')
        ->assertSet('error', fn (?string $e): bool => $e !== null && str_contains($e, 'expired or already finished'));
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
    Volt::test('auth.login')->assertSee('approve the device');

    Volt::test('auth.login')
        ->set('email', 'cli@acme.test')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('login')
        ->assertRedirect($complete);

    // And landing there resolves the code rather than showing it in a field.
    Volt::test('device', ['user_code' => $result->userCode])
        ->assertSet('verified', true)
        ->assertSet('clientName', 'TV App');
})->group('security');
