<?php

declare(strict_types=1);

use App\Listeners\RevokeTokensOnRoleChange;
use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;

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
