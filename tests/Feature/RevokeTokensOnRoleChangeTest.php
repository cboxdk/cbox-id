<?php

declare(strict_types=1);

use App\Listeners\RevokeTokensOnRoleChange;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Exceptions\InvalidGrant;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

function deliver(string $type, array $payload = [], ?string $orgId = null): EventDelivered
{
    $event = new Event;
    $event->type = $type;
    $event->payload = $payload;
    $event->organization_id = $orgId;

    return new EventDelivered($event);
}

it('revokes a user\'s refresh tokens when their role changes', function (string $type): void {
    $spy = Mockery::spy(RefreshTokens::class);
    app()->instance(RefreshTokens::class, $spy);

    app(RevokeTokensOnRoleChange::class)->handle(
        deliver($type, ['user_id' => 'user_1', 'role_id' => 'role_1'], 'org_1')
    );

    $spy->shouldHaveReceived('revokeForUser')->with('user_1', 'org_1')->once();
})->with(['role.assigned', 'role.unassigned']);

it('ignores events that are not role changes', function (): void {
    $spy = Mockery::spy(RefreshTokens::class);
    app()->instance(RefreshTokens::class, $spy);

    app(RevokeTokensOnRoleChange::class)->handle(deliver('user.updated', ['user_id' => 'user_1']));

    $spy->shouldNotHaveReceived('revokeForUser');
});

it('does nothing without a user id in the payload', function (): void {
    $spy = Mockery::spy(RefreshTokens::class);
    app()->instance(RefreshTokens::class, $spy);

    app(RevokeTokensOnRoleChange::class)->handle(deliver('role.assigned', ['role_id' => 'role_1'], 'org_1'));

    $spy->shouldNotHaveReceived('revokeForUser');
});

/**
 * End-to-end, not a spy: the tests above prove the listener CALLS revokeForUser, which
 * would still pass if the listener were never wired to the event bus or if the org scope
 * were wrong. This issues a REAL refresh token, runs the real listener, and asserts the
 * grant genuinely stops working — the property a downgraded user must not be able to ride.
 */
it('really stops a refresh token working after a role change', function (): void {
    $subject = app(Subjects::class)
        ->create('rotate@acme.test', 'Rae', 'a-strong-unbreached-passphrase');

    $org = app(Organizations::class)
        ->create(new NewOrganization('Acme', 'acme-'.uniqid()));

    $client = app(ClientRegistry::class)->register(
        new NewClient(
            name: 'Test App',
            type: ClientType::Confidential,
            redirectUris: ['https://app.test/callback'],
            scopes: ['openid'],
        )
    );

    $refreshTokens = app(RefreshTokens::class);
    $raw = $refreshTokens->issue($client->client, $subject->id, $org->id, ['openid']);

    app(RevokeTokensOnRoleChange::class)->handle(
        deliver('role.assigned', ['user_id' => $subject->id, 'role_id' => 'role_x'], $org->id)
    );

    expect(fn () => $refreshTokens->rotate($client->client->client_id, $raw))
        ->toThrow(InvalidGrant::class);
});
