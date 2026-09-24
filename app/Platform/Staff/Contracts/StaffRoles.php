<?php

declare(strict_types=1);

namespace App\Platform\Staff\Contracts;

use App\Platform\Staff\ValueObjects\StaffGrant;
use App\Platform\Staff\ValueObjects\StaffGrantRefusal;
use App\Platform\Staff\ValueObjects\StaffRole;
use Cbox\Id\AccessControl\Contracts\Roles;

/**
 * STAFF ROLES — roles held across a whole environment, by the environment's own people.
 *
 * A staff role is any role no organization owns, granted with
 * {@see Roles::assignEverywhere()}: it applies in every organization the person belongs to
 * (and to them when they belong to none). An app's own role granted this way reaches only
 * that app's tokens; an app-agnostic one reaches every app's. Organization administrators
 * never see these grants and cannot make or take them back.
 *
 * The console's one door for them — the Staff page and the user page both grant through
 * here — so segregation of duties is asked in exactly one way, and a refusal reads the same
 * wherever it is shown.
 */
interface StaffRoles
{
    /**
     * Every role that may be granted everywhere: no organization owns it, it is not
     * orphaned. App-agnostic roles first, then each app's, by name.
     *
     * @return list<StaffRole>
     */
    public function grantable(): array;

    /** Whether ONE role may be granted everywhere — asked of the database, never of a page. */
    public function isGrantable(string $roleId): bool;

    /**
     * Who holds what, environment-wide.
     *
     * @return list<StaffGrant>
     */
    public function grants(): array;

    /**
     * The role ids one person holds environment-wide.
     *
     * @return list<string>
     */
    public function heldBy(string $userId): array;

    /**
     * Grant a staff role, or say why segregation of duties forbids it. Null means granted.
     *
     * Asked in every organization the person belongs to — that is where the grant is about
     * to land — and against the environment-wide conflict rules for the staff roles they
     * already hold, which no organization's check can see.
     */
    public function grant(string $userId, string $roleId): ?StaffGrantRefusal;

    /** Take a staff role back. Revoking never conflicts. */
    public function revoke(string $userId, string $roleId): void;
}
