<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\DeviceAuthorization;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\DeviceCode;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

function signedInFor(): string
{
    $subject = app(Subjects::class)->create('dev@acme.test', 'Dev User', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-dev'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');

    return $subject->id;
}

function deviceClient(): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: 'TV App', type: ClientType::Confidential, grantTypes: ['urn:ietf:params:oauth:grant-type:device_code'], scopes: ['openid'],
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

    expect(DeviceCode::query()->value('status'))->toBe('denied');
});

it('prefills and upper-cases the code from the verification_uri_complete link', function () {
    signedInFor();

    Volt::test('device', ['user_code' => 'bcdf-ghjk'])
        ->assertSet('userCode', 'BCDF-GHJK');
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
