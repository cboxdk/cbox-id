<?php

declare(strict_types=1);

namespace App\Platform\OAuth\Contracts;

use App\Platform\OAuth\Exceptions\OrganizationCreationRefused;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;

/**
 * THE ORGANIZATIONS AN AUTHORIZATION MAY BE BOUND TO — the one answer behind the
 * `organization` parameter, the hosted picker and the hosted "create an organization" step.
 *
 * An organization qualifies when all three hold, and each is bound in the query rather than
 * compared afterwards, so the answer does not depend on the ambient tenant scope:
 *
 *  - it lives in the environment this request stands in;
 *  - it is live (not suspended, not deleted);
 *  - the person holds an ACTIVE membership in it — an unaccepted invitation or a suspended
 *    membership is not a way in, because the token that comes out asserts `org_role`.
 *
 * Nothing here writes to the session. Choosing an organization for an app binds that one
 * grant; it does not move the person's console, and it is not remembered for the next app.
 */
interface AuthorizationOrganizations
{
    /**
     * Every organization the person may bind an authorization to here, by name.
     *
     * @return list<OrganizationChoice>
     */
    public function choicesFor(string $userId): array;

    /** The organization, when the person may bind an authorization to it; otherwise null. */
    public function usableBy(string $userId, string $organizationId): ?OrganizationChoice;

    /**
     * Whether this environment lets a signed-in person found an organization of their own
     * from an app (`prompt=create_organization`).
     */
    public function creationOffered(): bool;

    /**
     * Found an organization with this person as its Owner, through the framework's own
     * organization and membership services (so `organization.created` and the membership
     * events are emitted and audited as for any other).
     *
     * @throws OrganizationCreationRefused
     */
    public function create(string $userId, string $name): OrganizationChoice;
}
