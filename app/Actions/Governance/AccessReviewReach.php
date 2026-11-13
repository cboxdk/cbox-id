<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Actions\Members\TenantRoster;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\OrgAccessRoles;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Enums\AccessKind;
use Cbox\Id\Governance\Enums\PendingPolicy;
use Cbox\Id\Governance\Enums\ReviewDecision;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Governance\Models\CertificationItem;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * What an access review may take away, asked of the person closing it.
 *
 * A review is a way of revoking access in bulk, and the framework applies its revokes
 * through the bare access contracts (`Memberships::remove`, `Roles::unassign`) with none of
 * the rules the direct roster actions enforce. So an organization administrator who is not
 * an owner, refused when they remove an owner on the People page, could open a review of
 * their own organization, leave the owners undecided under the default "revoke" policy and
 * close it — and every owner but the last was gone. The same went for roles the
 * organization may not assign, which an environment administrator granted inside it.
 *
 * A review closed from INSIDE the organization (an organization-confined principal) may
 * therefore take away exactly what that principal could take away directly:
 *
 *  - an owner's membership only when the closer is an owner ({@see TenantRoster::assertMayActOn()});
 *  - a role only when the organization may assign it ({@see OrgAccessRoles::isTenantAssignable()});
 *  - nothing at all in an organization whose roster is managed elsewhere
 *    ({@see TenantRoster::assertManagedHere()}).
 *
 * An environment administrator's reach is the environment's, and is unchanged.
 */
final readonly class AccessReviewReach
{
    public function __construct(
        private AccessReviews $reviews,
        private Memberships $memberships,
        private OrgAccessRoles $catalog,
    ) {}

    /** Refuse reviewing, from inside it, an organization whose roster is managed elsewhere. */
    public static function assertManagedHere(ActionContext $context, ?string $organizationId): void
    {
        if ($organizationId !== null && TenantRoster::confined($context) !== null) {
            TenantRoster::assertManagedHere($organizationId, 'id');
        }
    }

    /** Why this principal may not revoke the item, or null when they may. */
    public function outOfReach(ActionContext $context, CertificationItem $item): ?string
    {
        $organizationId = $item->organization_id;

        if ($organizationId === null || TenantRoster::confined($context) === null) {
            return null;
        }

        if ($item->access_type === AccessKind::Membership) {
            // The role the person holds NOW, not the one the snapshot recorded: somebody made
            // an owner after the review opened is an owner when it closes.
            $role = $this->memberships->of($organizationId, $item->subject_id)?->role;

            return $role === MembershipRole::Owner && ! TenantRoster::isOwner($context->principal, $organizationId)
                ? 'Only an owner can remove an owner.'
                : null;
        }

        if ($item->access_type === AccessKind::Role && ! $this->catalog->isTenantAssignable($organizationId, $item->access_ref)) {
            return 'This role is not one the organization assigns, so it is not the organization\'s to take away.';
        }

        return null;
    }

    /** Refuse an explicit revoke of an item out of this principal's reach. */
    public function assertMayRevoke(ActionContext $context, CertificationItem $item): void
    {
        $why = $this->outOfReach($context, $item);

        if ($why !== null) {
            throw new ActionRefused('out_of_reach', $why.' Certify it, or ask an environment administrator to review it.', 403, 'decision');
        }
    }

    /**
     * Refuse a close that would revoke anything out of this principal's reach — an explicit
     * revoke, or an item left undecided under a "revoke" pending policy.
     */
    public function assertMayClose(ActionContext $context, CertificationCampaign $campaign): void
    {
        if (TenantRoster::confined($context) === null || $campaign->organization_id === null) {
            return;
        }

        $blocked = 0;

        foreach ($this->reviews->itemsFor($campaign->id) as $item) {
            $revokes = $item->decision === ReviewDecision::Revoked
                || ($item->decision === ReviewDecision::Pending && $campaign->pending_policy === PendingPolicy::Revoke);

            if ($revokes && $this->outOfReach($context, $item) !== null) {
                $blocked++;
            }
        }

        if ($blocked > 0) {
            throw new ActionRefused(
                'out_of_reach',
                ($blocked === 1 ? 'One item' : $blocked.' items').' in this review would take away access you cannot take away yourself — an owner\'s membership, or a role the organization does not assign. Certify '.($blocked === 1 ? 'it' : 'them').' before closing, or ask an owner or an environment administrator to close the review.',
                403,
                'id',
            );
        }
    }
}
