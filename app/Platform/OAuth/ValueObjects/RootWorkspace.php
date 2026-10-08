<?php

declare(strict_types=1);

namespace App\Platform\OAuth\ValueObjects;

use App\Platform\OrganizationCapabilities;
use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * The workspace a root-host token's person is on the team of, as it stands NOW: an active
 * membership of a live organization that owns products — the same three facts the
 * workspace console and the environment handoff ask before they let a person in.
 *
 * Read when the token is presented, never from the token: `org_role` is what the role was
 * when the token was minted, and a person demoted or removed since keeps neither.
 */
final readonly class RootWorkspace
{
    /**
     * @param  list<string>  $environmentIds  the environments the membership reaches (all of the workspace's, or the ones it was scoped to)
     */
    public function __construct(
        public string $id,
        public string $name,
        public MembershipRole $role,
        public bool $allEnvironments,
        public array $environmentIds,
    ) {}

    public function capabilities(): OrganizationCapabilities
    {
        return OrganizationCapabilities::of($this->role);
    }

    /**
     * Whether the person may act in an environment of this workspace at all: the two
     * questions `/open/{environment}` asks before it mints a handoff — the role administers
     * environments, and the membership reaches at least one.
     */
    public function administersEnvironments(): bool
    {
        return $this->capabilities()->canManageEnvironments() && $this->environmentIds !== [];
    }
}
