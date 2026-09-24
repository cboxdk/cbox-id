<?php

declare(strict_types=1);

namespace App\Platform\Invitations\Contracts;

use App\Mail\OrganizationInviteMail;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\InvitationPreview;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Invitation;

/**
 * INVITING SOMEBODY ONTO A WORKSPACE'S TEAM — the people who run a customer's identity
 * platform (Workspace › Team), from whichever door asks: the console page, or
 * `POST /api/v1/organization/members` with a workspace key.
 *
 * NOT {@see OrganizationInvitations}. That one invites somebody to be one of a tenant
 * organization's PEOPLE — an end user of the apps built on an environment, with access
 * roles, a way back to the app, and an accept step that signs nobody in. This one invites
 * somebody to ADMINISTER the workspace: the roles are the workspace's own (Admin,
 * Developer, Member, Viewer — never Owner, which is transferred), the invitation lives in
 * the platform root, the mail is {@see OrganizationInviteMail} ("… invited you to
 * administer …"), and accepting it sets a password on a signed link and signs the person in
 * to the console. The two kinds share the framework's invitation table and nothing else.
 *
 * Both doors had their own copy and they had drifted: the API sent no role in the mail,
 * recorded nothing on the activity log, left a dead invitation behind when the mail
 * failed, and could neither list, re-send nor withdraw what it sent.
 *
 * Every write takes the ORGANIZATION ID from the caller's scope and binds it in the query
 * that finds the invitation.
 */
interface TeamInvitations
{
    /**
     * Invite an address onto the team and mail the link.
     *
     * @throws InvitationRefused role not offered, already on the team, or the mail failed
     *                           (in which case nothing was created)
     */
    public function send(string $organizationId, string $email, MembershipRole $role, Inviter $inviter, AuditActor $actor): Invitation;

    /**
     * Issue a fresh link for a pending invitation and mail it again; the earlier link stops
     * working. At most once a minute per address.
     *
     * @throws InvitationRefused not pending, too soon, or the mail failed (the invitation
     *                           stays)
     */
    public function resend(string $organizationId, string $invitationId, Inviter $inviter, AuditActor $actor): Invitation;

    /**
     * Withdraw a pending invitation. Its link stops working.
     *
     * @throws InvitationRefused when there is no such pending invitation on this team
     */
    public function revoke(string $organizationId, string $invitationId, AuditActor $actor): void;

    /**
     * The newest pending invitations.
     *
     * @return list<PendingInvitationSummary>
     */
    public function pending(string $organizationId, int $limit = 25): array;

    public function countPending(string $organizationId): int;

    /** What a live team invitation says, without spending it — null when it is not live. */
    public function preview(string $token): ?InvitationPreview;
}
