<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\AuthenticateOrganizationApi;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Models\OrganizationApiKey;

/**
 * The organization API key authenticated for the current request — the machine equivalent
 * of a member's session, on the global management plane.
 *
 * Deliberately NOT environment-scoped: the key operates above every environment the
 * organization owns. Bound per-request (scoped) and populated by
 * {@see AuthenticateOrganizationApi}.
 */
final class OrganizationApiContext
{
    private ?OrganizationApiKey $key = null;

    public function set(OrganizationApiKey $key): void
    {
        $this->key = $key;
    }

    public function key(): ?OrganizationApiKey
    {
        return $this->key;
    }

    public function organizationId(): ?string
    {
        return $this->key?->organization_id;
    }

    public function role(): ?MembershipRole
    {
        return $this->key?->role;
    }
}
