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

it('approves a device from the verification screen and the device can then get a token', function () {
    $userId = signedInFor();
    $result = app(DeviceAuthorization::class)->request(deviceClient(), ['openid']);

    Volt::test('device')
        ->set('userCode', $result->userCode)
        ->call('approve')
        ->assertSet('outcome', 'approved');

    // Skip the poll interval, then the device redeems its token bound to the user.
    DeviceCode::query()->update(['last_polled_at' => now()->subMinute()]);
    $grant = app(DeviceAuthorization::class)->redeem(
        DeviceCode::query()->value('client_id'),
        $result->deviceCode,
    );

    expect($grant->userId)->toBe($userId);
});

it('reports an invalid or unknown code', function () {
    signedInFor();

    Volt::test('device')
        ->set('userCode', 'ZZZZ-ZZZZ')
        ->call('approve')
        ->assertSet('outcome', 'invalid');
});

it('denies a device', function () {
    signedInFor();
    $result = app(DeviceAuthorization::class)->request(deviceClient(), ['openid']);

    Volt::test('device')
        ->set('userCode', $result->userCode)
        ->call('deny')
        ->assertSet('outcome', 'denied');

    expect(DeviceCode::query()->value('status'))->toBe('denied');
});
