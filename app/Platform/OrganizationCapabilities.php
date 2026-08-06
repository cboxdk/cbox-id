<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * What the acting person may do on the organization they are administering.
 *
 * WHY A VALUE OBJECT AND NOT THE ENUM. Every one of these questions was asked directly of
 * the account plane's own role enum at thirty-odd call sites — a nav entry, a console-kit
 * feature closure, each page's `mount()`, and the API middleware. That was fine while the
 * account plane had its own role column and its own console. It stopped being fine the
 * moment an account became an organization: the capability is derived from a
 * {@see MembershipRole} plus refinements the role enum does not express, and thirty call
 * sites would have been thirty places to derive it differently.
 *
 * This is the one place, and it is why the fold could be done without touching those call
 * sites: this file was written to take `MembershipRole` a batch BEFORE the account plane
 * was deleted, so removing the plane changed the input to this class and nothing else.
 * `ofMembershipRole()` was the bridge for the paths that still resolved a member row; there
 * are no member rows, so it is gone rather than left as a second way in.
 *
 * THREE OF THESE ARE NOT WHAT A NAIVE TRANSLATION WOULD HAVE PRODUCED, and each is written
 * down where it is defined so a later simplification cannot lose it by substitution:
 *
 *  - {@see canManageEnvironments()} is NOT `MembershipRole::canWrite()`. That returns true
 *    for `Member`, the role an ordinary person gets for merely being placed in the
 *    organization, so the substitution grants environment administration — and a live
 *    environment-admin session on a tenant host — to every member of every customer.
 *  - {@see canReadMembers()} and {@see canReadBilling()} have no counterpart at all, and
 *    both are FALSE for a Developer by design. Dropping them hands the member roster (PII)
 *    to a leaked developer credential.
 *
 * ASKED OF THE ACTING PERSON ONLY. A target member's own role is a property of that
 * member, not a capability of the person looking at them — `supportsEnvironmentScoping()`
 * on a row in the roster, or the Owner check that stops an admin re-roling an owner, stay
 * on the enum where they read as what they are. Routing those through here would make
 * "what may I do" and "what is that person" the same sentence, which is the confusion
 * {@see ConsoleScope} exists to prevent.
 */
final readonly class OrganizationCapabilities
{
    private function __construct(private MembershipRole $role) {}

    public static function of(MembershipRole $role): self
    {
        return new self($role);
    }

    /** Invite, remove, and change the role of other members. */
    public function canManageMembers(): bool
    {
        return $this->role->canManageOrganization();
    }

    /**
     * Read the member roster, which is PII. Owners, admins and the read-only Viewer;
     * NOT a Developer — a leaked technical credential must not enumerate the team — and
     * not a Billing-only role.
     */
    public function canReadMembers(): bool
    {
        return $this->role->canReadMembers();
    }

    /**
     * Create environments and manage their settings.
     *
     * The one that must not become `canWrite()`. See the class docblock.
     */
    public function canManageEnvironments(): bool
    {
        return $this->role->canManageEnvironments();
    }

    /** Read the plan, billing and usage. Managers plus the Viewer; not a Developer. */
    public function canReadBilling(): bool
    {
        return $this->role->canReadBilling();
    }

    /** Change the plan. */
    public function canManageBilling(): bool
    {
        // Not representable on the organization plane, and nothing asks for it — no page,
        // no route. Answered as the manage-the-organization question rather than invented,
        // so the one caller left (the machine plane's capability map) keeps a defensible
        // answer until it folds too.
        return $this->role->canManageOrganization();
    }

    /**
     * The role itself, for the places that legitimately need the VALUE rather than a
     * capability — stamping a machine credential with the role it carries, and rendering
     * the role a person holds. Kept narrow on purpose: a caller reaching for this to ask
     * a yes/no question is asking it in the wrong place.
     */
    public function role(): MembershipRole
    {
        return $this->role;
    }
}
