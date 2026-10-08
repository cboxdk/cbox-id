<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\Actions\Principal\PersonPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\CurrentUser;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The rules an organization's OWN roster is kept by, when it is administered from inside the
 * organization. A helper, not an action.
 *
 * The roster actions (invite, re-role, grant, remove, hand over) are one set of actions with
 * two kinds of caller. The environment's authority — its console, its management keys —
 * stands above every organization and keeps exactly the rules it always had. A person
 * acting from INSIDE an organization — its own console, or a token they signed in for, both
 * confined to it ({@see Principal::confinedToOrganization()}) — is a member of the roster
 * they are changing, and three things follow that the environment's authority never has to
 * ask:
 *
 *  - who they are: the person, so "only an owner touches an owner" and "not yourself" can
 *    be asked at all ({@see self::personId()});
 *  - what they hold there: an admin administers the roster, and only an owner may act on
 *    an owner or hand the organization over ({@see self::isOwner()});
 *  - whose roster it is: a CUSTOMER's — an organization that owns products — is
 *    administered from Workspace › Team, never from here ({@see self::managedElsewhere()}).
 *
 * Asked by each action only when the principal is confined, so nothing about the environment
 * console or a key changes.
 */
final class TenantRoster
{
    /** The sentence a refused roster write gets, and the People page's own banner. */
    public const string MANAGED_ELSEWHERE = 'This organization is a Cbox workspace. Its team is managed under Workspace › Team.';

    /**
     * The organization the caller acts from inside of, or null for the environment's own
     * authority, which keeps its own rules.
     *
     * @throws AuthorizationException
     */
    public static function confined(ActionContext $context): ?string
    {
        return $context->principal->confinedToOrganization();
    }

    /**
     * The person acting from inside the organization: the signed-in subject on the
     * organization console ({@see CurrentUser}), the person a token stands for otherwise.
     * Nobody else acts from inside one.
     *
     * @throws AuthorizationException
     */
    public static function personId(Principal $principal): string
    {
        $id = match (true) {
            $principal instanceof DelegatedTokenPrincipal, $principal instanceof PersonPrincipal => $principal->subjectId(),
            $principal instanceof ConsoleSessionPrincipal => app(CurrentUser::class)->check()
                ? app(CurrentUser::class)->id()
                : $principal->scope()->actorId(),
            default => null,
        };

        if (! is_string($id) || $id === '') {
            throw new AuthorizationException('Only a member of this organization can change its people.');
        }

        return $id;
    }

    /**
     * The acting person's own membership of $organizationId, read fresh rather than from a
     * session that may predate a transfer.
     *
     * @throws AuthorizationException
     */
    public static function membership(Principal $principal, string $organizationId): ?Membership
    {
        return app(Memberships::class)->of($organizationId, self::personId($principal));
    }

    /** @throws AuthorizationException */
    public static function isOwner(Principal $principal, string $organizationId): bool
    {
        return self::membership($principal, $organizationId)?->role === MembershipRole::Owner;
    }

    /**
     * Only an owner may act on an owner: an admin cannot demote or remove the person who
     * can close the organization.
     *
     * @throws AuthorizationException
     */
    public static function assertMayActOn(Principal $principal, string $organizationId, Membership $target): void
    {
        if ($target->role === MembershipRole::Owner && ! self::isOwner($principal, $organizationId)) {
            throw new AuthorizationException('Only an owner can change another owner.');
        }
    }

    /**
     * Refuse when the organization's roster is administered elsewhere — on the field the
     * page shows it on.
     *
     * @throws ActionRefused
     */
    public static function assertManagedHere(string $organizationId, string $field): void
    {
        if (self::managedElsewhere($organizationId)) {
            throw new ActionRefused('managed_elsewhere', self::MANAGED_ELSEWHERE, 409, $field);
        }
    }

    /**
     * Whether the organization's roster belongs to the MANAGEMENT console rather than to its
     * own People page.
     *
     * THE ORIGINAL REASON IS GONE, and the replacement is narrower — worth stating plainly so
     * nobody restores the old one. This used to ask whether a person's place was governed by
     * an ACCOUNT membership, because there were two writers of one person's role. There is
     * ONE row now: both consoles write `memberships.role`, so they cannot disagree, and that
     * half of the argument retires with the plane it described.
     *
     * What remains is a boundary rather than a consistency problem: an organization that owns
     * PRODUCTS is a customer, and a customer's roster is administered by somebody holding an
     * organization capability — not from a console whose authority is "administers this one
     * environment". An operator pointing that console at the platform root would otherwise be
     * able to re-role a customer's owner from a page that never asked whether they may.
     *
     * Asked of the ORGANIZATION, not of the person: every member of a customer is covered,
     * including one added after this check was written.
     */
    public static function managedElsewhere(string $organizationId): bool
    {
        return app(PlatformRoot::class)->run(
            static fn (): bool => app(OrganizationProjects::class)->forOrganization($organizationId)->isNotEmpty(),
        ) === true;
    }
}
