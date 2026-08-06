<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Models\Project;

/**
 * A self-serve customer that exists but is not yet an IdP: the organization, the owner who
 * signs in for it, that owner's membership and their first project, with NO environment
 * behind it. The environment — the expensive, routable, key-bearing part — is stood up by
 * {@see SignupProvisioner::releaseEnvironment()} once the owner proves the email.
 *
 * Deliberately NOT a `ProvisionedTenant`: that value object promises an `Environment`, and
 * this state is precisely the absence of one.
 *
 * FOUR THINGS, and it used to be three with a different first one. There was an `Account`
 * row above the organization; the owner was a member row that carried its own password, and
 * the organization was created beside the account and pointed back at it. The owner is now a
 * subject (who they are) plus a membership (what they may do here) — the same split
 * {@see \Cbox\Id\Platform\ValueObjects\ProvisionedTenant} makes, because it is the same
 * customer at an earlier moment.
 */
final readonly class PendingOrganization
{
    public function __construct(
        public Organization $organization,
        public Subject $owner,
        public Membership $membership,
        public Project $project,
    ) {}
}
