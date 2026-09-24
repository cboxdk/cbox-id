<?php

declare(strict_types=1);

namespace App\Platform\Invitations\Contracts;

use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\AcceptedInvitation;
use App\Platform\Invitations\ValueObjects\InvitationPreview;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use App\Platform\Invitations\ValueObjects\SentInvitation;
use Cbox\Id\Organization\Exceptions\InvalidInvitation;

/**
 * INVITING SOMEBODY INTO AN ORGANIZATION — one service, whichever page does it.
 *
 * There were three: the organization's own People page, the environment console's
 * organization page, and (for customers) Identity platform › Administrators. Each minted the
 * invitation, parked the access roles, built the link and sent the mail itself, so they
 * drifted — two mails with the same subject line, three role lists, and only one of them
 * could re-send. This is the tenant-organization path in one place: send, re-send, withdraw,
 * list, and the two halves of accepting (read without spending; spend).
 *
 * Every write takes the ORGANIZATION ID from the caller's scope and binds it in the query
 * that finds the invitation, so an invitation id carried from another organization resolves
 * to nothing rather than to a row that is compared afterwards.
 *
 * The other KIND — onto a workspace's team, its administrators — is {@see TeamInvitations},
 * shared by the console's Team page and the workspace API: that invite is accepted by
 * setting a password on a signed link, and folding the two is a data migration rather
 * than a refactor.
 */
interface OrganizationInvitations
{
    /**
     * Create the invitation, park its access roles and its app context, and mail it.
     *
     * @throws InvitationRefused
     */
    public function send(NewInvitation $invitation): SentInvitation;

    /**
     * Issue a fresh link for a pending invitation and mail it again. The earlier link stops
     * working; the roles and app context it carried move to the new one.
     *
     * @throws InvitationRefused
     */
    public function resend(string $organizationId, string $invitationId, Inviter $inviter): SentInvitation;

    /**
     * Withdraw a pending invitation, and the roles and context parked against it.
     *
     * @throws InvitationRefused when there is no such pending invitation in this organization
     */
    public function revoke(string $organizationId, string $invitationId, ?string $actorId): void;

    /**
     * The newest pending invitations, as every invite surface lists them.
     *
     * @return list<PendingInvitationSummary>
     */
    public function pending(string $organizationId, int $limit = 25): array;

    /** What a live invitation says, without spending it — null when it is not live. */
    public function preview(string $token): ?InvitationPreview;

    /**
     * Spend the invitation: resolve (or create) the invitee, grant the membership and the
     * parked access roles, and say where to send them.
     *
     * @throws InvalidInvitation when the token is unknown, spent, withdrawn or expired
     */
    public function accept(string $token): AcceptedInvitation;
}
