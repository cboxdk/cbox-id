<?php

declare(strict_types=1);

namespace App\Listeners;

use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;

/**
 * When an organization is closed, every member's access to it is over — withdraw it.
 *
 * {@see Organizations::archive()} and `archiveAsOwner()` set the status and announce
 * `organization.deleted`, and the request pipeline refuses the members from then on. The
 * refresh tokens they hold in that organization were never touched: the token endpoint
 * does not ask the organization's status on a refresh, so an application went on minting
 * access tokens for a closed organization, and nothing told it the people it had signed in
 * there had lost access.
 *
 * {@see RefreshTokens::withdrawAccess()} per member, scoped to the organization, is the
 * framework's own verb for exactly that — revoke the grants and send each application that
 * held them a back-channel logout. Their access elsewhere is untouched. The memberships
 * are kept (an archive is not an erase), so the roster is still there to read.
 *
 * From the outbox, like the framework's own membership-removal listener, so it runs once
 * the archive has committed and inside the event's environment. Delivery is at least once;
 * a second pass revokes nothing and notifies nobody.
 */
final readonly class WithdrawAccessOnOrganizationClosed
{
    /** Emitted once per archive; the legacy `organization.archived` beside it is the same fact. */
    public const string EVENT = 'organization.deleted';

    public function __construct(
        private Memberships $memberships,
        private RefreshTokens $refreshTokens,
    ) {}

    public function handle(EventDelivered $delivered): void
    {
        $event = $delivered->event;

        if ($event->type !== self::EVENT) {
            return;
        }

        $organizationId = $event->organization_id;

        // Never widened to the whole subject: that would sign people out of every other
        // organization they still belong to.
        if (! is_string($organizationId) || $organizationId === '') {
            return;
        }

        foreach ($this->memberships->userIdsForOrganization($organizationId) as $userId) {
            $this->refreshTokens->withdrawAccess($userId, $organizationId);
        }
    }
}
