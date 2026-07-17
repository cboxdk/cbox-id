<?php

declare(strict_types=1);

namespace App\Listeners;

use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;

/**
 * When a user's role is assigned or unassigned, revoke their refresh tokens so the
 * next refresh forces re-authentication and re-mints a token carrying the new roles
 * and permissions — the "freshness" half of the federated RBAC model. Access tokens
 * already self-heal within their (short, configurable) TTL; this closes the
 * refresh-token gap so a downgrade takes effect promptly instead of riding a stale
 * grant to expiry. Reacts to the AccessControl domain-event outbox, so it stays
 * decoupled from the RoleService that emits.
 */
final class RevokeTokensOnRoleChange
{
    public function __construct(private readonly RefreshTokens $refreshTokens) {}

    public function handle(EventDelivered $delivered): void
    {
        $event = $delivered->event;

        if (! in_array($event->type, ['role.assigned', 'role.unassigned'], true)) {
            return;
        }

        $userId = $event->payload['user_id'] ?? null;

        if (is_string($userId) && $userId !== '') {
            $this->refreshTokens->revokeForUser($userId, $event->organization_id);
        }
    }
}
