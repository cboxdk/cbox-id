<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Enums\AccountRole;

/**
 * What the acting person may do on the account they are administering.
 *
 * WHY A VALUE OBJECT AND NOT THE ENUM. Every one of these questions was asked directly
 * of {@see AccountRole} at thirty-odd call sites — a nav entry, a console-kit feature
 * closure, each page's `mount()`, and the API middleware. That was fine while the account
 * plane had its own role column and its own console. It stops being fine the moment an
 * account IS an organization: the capability then has to be derived from a
 * {@see MembershipRole} plus a refinement the organization plane cannot express, and
 * thirty call sites is thirty places to derive it differently.
 *
 * This is the one place. `AccountRole` becomes the input rather than the answer, and the
 * batch that flips the input changes this file instead of the console.
 *
 * THE EXTENSIONS ARE COPIED EXACTLY, deliberately, and this batch changes no verdict —
 * that is what makes it safe to land ahead of the fold. Three of them do NOT survive a
 * naive translation to `MembershipRole`, and each is written down where it is defined so
 * the next batch cannot lose it by substitution:
 *
 *  - {@see canManageEnvironments()} is NOT `MembershipRole::canWrite()`. That returns true
 *    for `Member`, which is the role every account member actually carries today, so the
 *    substitution grants environment administration — and a live environment-admin session
 *    on a tenant host — to everyone merely placed in the account's organization.
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
final readonly class AccountCapabilities
{
    private function __construct(private AccountRole $role) {}

    public static function of(AccountRole $role): self
    {
        return new self($role);
    }

    /** Invite, remove, and change the role of other members. */
    public function canManageMembers(): bool
    {
        return $this->role->canManageMembers();
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

    /** Change the plan. Owner, Admin and the Billing role. */
    public function canManageBilling(): bool
    {
        return $this->role->canManageBilling();
    }

    /**
     * The role itself, for the places that legitimately need the VALUE rather than a
     * capability — stamping a machine credential with the role it carries, and rendering
     * the role a person holds. Kept narrow on purpose: a caller reaching for this to ask
     * a yes/no question is asking it in the wrong place.
     */
    public function role(): AccountRole
    {
        return $this->role;
    }
}
