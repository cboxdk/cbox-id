<?php

declare(strict_types=1);

namespace App\Platform\Membership;

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OwnershipTransferRefusal;
use Cbox\Id\Organization\Exceptions\LastOwner;
use Cbox\Id\Organization\Exceptions\NotOrganizationOwner;
use Cbox\Id\Organization\Exceptions\OwnershipTransferRefused;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;

/**
 * THE THREE MEMBERSHIP CHANGES A PERSON MAKES ABOUT THEMSELVES OR THEIR ORGANIZATION —
 * handing it over, leaving it, and closing it.
 *
 * ONE OWNER, MOVED BY TRANSFER. The framework's rule is that ownership is transferred, never
 * assigned ({@see MembershipRole::assignable()}), and the People page no longer offers
 * "Owner" in its role picker; this is the verb that replaced it.
 *
 * THE FRAMEWORK OWNS THE VERBS NOW. {@see Memberships::transferOwnership()},
 * {@see Memberships::leave()} and {@see Organizations::archiveAsOwner()} lock the rows they
 * decide on, bind the organization in their WHERE clauses and write their own audit entries
 * and webhook events. This class used to do each by hand — two `changeRole()` calls, a
 * `remove()`, an operator's `archive()` — and wrote a second audit entry for each. What is
 * left here is only what the framework does not state:
 *
 * - the sentence a person is shown ({@see MembershipRefused}), mapped from the framework's
 *   typed refusal;
 * - an ENVIRONMENT ADMINISTRATOR re-assigning ownership from outside the organization,
 *   where there is no outgoing owner to name (the framework's transfer is owner-to-member);
 * - closing an organization only when its name is typed, and never one that owns
 *   identity-provider products.
 */
final readonly class MembershipLifecycle
{
    public function __construct(
        private Memberships $memberships,
        private Organizations $organizations,
        private OrganizationProjects $projects,
        private PlatformRoot $platformRoot,
        private AuditLog $audit,
    ) {}

    /**
     * Make `$toUserId` the owner.
     *
     * `$fromUserId` is the owner handing over, who stays on as an admin — the framework's
     * {@see Memberships::transferOwnership()}, which audits it as the owner's act. Null when
     * an environment administrator re-assigns ownership from outside the organization —
     * then EVERY current owner steps down to admin, which is also what tidies an
     * organization that collected several owners before this rule existed, and gives one
     * this console created with no owner its first.
     *
     * @throws MembershipRefused
     */
    public function transferOwnership(string $organizationId, string $toUserId, ?string $fromUserId, ?string $actorId): void
    {
        $target = $this->memberships->of($organizationId, $toUserId) ?? throw MembershipRefused::notAMember();

        if ($fromUserId !== null) {
            try {
                $this->memberships->transferOwnership($organizationId, $fromUserId, $toUserId);
            } catch (OwnershipTransferRefused $refused) {
                throw match ($refused->reason) {
                    OwnershipTransferRefusal::NotOwner => MembershipRefused::notTheOwner(),
                    OwnershipTransferRefusal::TargetNotMember => MembershipRefused::notAMember(),
                    OwnershipTransferRefusal::TargetNotActive => MembershipRefused::notActive(),
                    OwnershipTransferRefusal::SameMember => MembershipRefused::alreadyOwner(),
                };
            }

            return;
        }

        // The same rule the framework's transfer holds to: an invitation nobody accepted,
        // or a suspended member, cannot be handed an organization.
        if ($this->memberships->activeRole($organizationId, $toUserId) === null) {
            throw MembershipRefused::notActive();
        }

        $outgoing = array_values(array_filter(
            $this->memberships->owners($organizationId),
            static fn (string $userId): bool => $userId !== $toUserId,
        ));

        if ($target->role === MembershipRole::Owner && $outgoing === []) {
            throw MembershipRefused::alreadyOwner();
        }

        // Promote first, then demote: {@see Memberships::changeRole()} refuses to demote
        // the last owner, so the other order would be refused outright.
        DB::transaction(function () use ($organizationId, $toUserId, $outgoing): void {
            $this->memberships->changeRole($organizationId, $toUserId, MembershipRole::Owner);

            foreach ($outgoing as $userId) {
                $this->memberships->changeRole($organizationId, $userId, MembershipRole::Admin);
            }
        });

        $this->audit->record(new AuditEvent(
            action: 'organization.ownership_transferred',
            actorType: ActorType::OrganizationMember,
            actorId: $actorId,
            organizationId: $organizationId,
            targetType: 'user',
            targetId: $toUserId,
            context: ['from' => $outgoing, 'to_user_id' => $toUserId],
        ));
    }

    /**
     * Leave an organization of one's own accord — {@see Memberships::leave()}, which drops
     * the person's grants with the membership and audits `organization.member_removed`
     * with `reason: left`, attributed to them.
     *
     * Refused for the last owner, with the way out named: an organization with no owner has
     * nobody who can hand it over or close it, so the owner transfers first or deletes it.
     *
     * @throws MembershipRefused
     */
    public function leave(string $organizationId, string $userId): void
    {
        // The framework's leave is idempotent — right for a retried API call, wrong for a
        // button that should say why nothing happened.
        if ($this->memberships->of($organizationId, $userId) === null) {
            throw MembershipRefused::notAMember();
        }

        try {
            $this->memberships->leave($organizationId, $userId);
        } catch (LastOwner) {
            throw MembershipRefused::lastOwner();
        }
    }

    /**
     * Close an organization — its OWNER's decision, confirmed by typing its name.
     *
     * Archived rather than erased ({@see Organizations::archiveAsOwner()}): the rows stay for
     * the audit trail and any regulatory hold, and every member loses access at once. The
     * framework re-checks the active owner membership under a row lock, so a transfer
     * racing this cannot close an organization its sender no longer owns.
     *
     * An organization that owns identity-provider PRODUCTS is a customer of this platform,
     * with projects, environments and a bill hanging off it; closing one of those is not a
     * one-field form, so it is refused here with the place it is done instead.
     *
     * @throws MembershipRefused
     */
    public function archive(string $organizationId, string $ownerId, string $typedName): Organization
    {
        $organization = $this->organizations->find($organizationId) ?? throw MembershipRefused::notAMember();

        // Asked before the name, so a non-owner is told the rule rather than a typo.
        if ($this->memberships->activeRole($organizationId, $ownerId) !== MembershipRole::Owner) {
            throw MembershipRefused::notTheOwner();
        }

        if ($typedName !== $organization->name) {
            throw MembershipRefused::nameMismatch();
        }

        $ownsProducts = $this->platformRoot->run(
            fn (): bool => $this->projects->forOrganization($organizationId)->isNotEmpty(),
        ) === true;

        if ($ownsProducts) {
            throw MembershipRefused::ownsProducts();
        }

        try {
            return $this->organizations->archiveAsOwner($organizationId, $ownerId);
        } catch (NotOrganizationOwner) {
            throw MembershipRefused::notTheOwner();
        }
    }
}
