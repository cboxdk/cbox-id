<?php

declare(strict_types=1);

namespace App\Platform\Membership;

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Exceptions\LastOwner;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;

/**
 * THE THREE MEMBERSHIP CHANGES A PERSON MAKES ABOUT THEMSELVES OR THEIR ORGANIZATION —
 * handing it over, leaving it, and closing it.
 *
 * ONE OWNER, MOVED BY TRANSFER. The framework's rule is that ownership is transferred, never
 * assigned ({@see MembershipRole::assignable()}), and the customer console always honoured
 * it; the People page did not, and offered "Owner" in its role picker. That picker no longer
 * offers it, and this is the verb that replaced it: the new owner is promoted FIRST and the
 * old one demoted second, inside one transaction, so the organization has two owners for an
 * instant and never none — {@see Memberships} refuses to demote the last owner, so the other
 * order would be refused outright.
 *
 * Built on the framework's own membership and organization services, so every change still
 * emits and audits through them; the app adds only the rules they do not state.
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
     * `$fromUserId` is the owner handing over, who stays on as an admin. Null when an
     * environment administrator re-assigns ownership from outside the organization — then
     * EVERY current owner steps down to admin, which is also what tidies an organization
     * that collected several owners before this rule existed.
     *
     * @throws MembershipRefused
     */
    public function transferOwnership(string $organizationId, string $toUserId, ?string $fromUserId, ?string $actorId): void
    {
        $target = $this->memberships->of($organizationId, $toUserId) ?? throw MembershipRefused::notAMember();

        if ($fromUserId !== null && $this->memberships->of($organizationId, $fromUserId)?->role !== MembershipRole::Owner) {
            throw MembershipRefused::notTheOwner();
        }

        $outgoing = $fromUserId !== null
            ? [$fromUserId]
            : $this->memberships->forOrganization($organizationId)
                ->filter(fn (Membership $m): bool => $m->role === MembershipRole::Owner && $m->user_id !== $toUserId)
                ->pluck('user_id')
                ->values()
                ->all();

        if ($target->role === MembershipRole::Owner && $outgoing === []) {
            throw MembershipRefused::alreadyOwner();
        }

        DB::transaction(function () use ($organizationId, $toUserId, $outgoing): void {
            $this->memberships->changeRole($organizationId, $toUserId, MembershipRole::Owner);

            foreach ($outgoing as $userId) {
                if (is_string($userId) && $userId !== $toUserId) {
                    $this->memberships->changeRole($organizationId, $userId, MembershipRole::Admin);
                }
            }
        });

        $this->record('organization.ownership_transferred', $organizationId, $actorId, 'user', $toUserId, [
            'from' => array_values(array_filter($outgoing, 'is_string')),
        ]);
    }

    /**
     * Leave an organization of one's own accord.
     *
     * Refused for the last owner, with the way out named: an organization with no owner has
     * nobody who can hand it over or close it, so the owner transfers first or deletes it.
     *
     * @throws MembershipRefused
     */
    public function leave(string $organizationId, string $userId): void
    {
        if ($this->memberships->of($organizationId, $userId) === null) {
            throw MembershipRefused::notAMember();
        }

        try {
            $this->memberships->remove($organizationId, $userId);
        } catch (LastOwner) {
            throw MembershipRefused::lastOwner();
        }

        $this->record('organization.member_left', $organizationId, $userId, 'user', $userId);
    }

    /**
     * Close an organization — its OWNER's decision, confirmed by typing its name.
     *
     * Archived rather than erased ({@see Organizations::archive()}): the rows stay for the
     * audit trail and any regulatory hold, and every member loses access at once, exactly as
     * when an environment administrator deletes it.
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

        if ($this->memberships->of($organizationId, $ownerId)?->role !== MembershipRole::Owner) {
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

        return $this->organizations->archive($organizationId, $ownerId);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, string $organizationId, ?string $actorId, string $targetType, string $targetId, array $context = []): void
    {
        $this->audit->record(new AuditEvent(
            action: $action,
            actorType: ActorType::User,
            actorId: $actorId,
            organizationId: $organizationId,
            targetType: $targetType,
            targetId: $targetId,
            context: $context,
        ));
    }
}
