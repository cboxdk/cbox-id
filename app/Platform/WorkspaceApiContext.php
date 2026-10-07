<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\AuthenticateWorkspaceApi;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Models\OrganizationApiKey;

/**
 * The organization API key authenticated for the current request — the machine equivalent
 * of a member's session, on the global management plane.
 *
 * Deliberately NOT environment-scoped: the key operates above every environment the
 * organization owns. Bound per-request (scoped) and populated by
 * {@see AuthenticateWorkspaceApi}.
 */
final class WorkspaceApiContext
{
    private ?OrganizationApiKey $key = null;

    public function set(OrganizationApiKey $key): void
    {
        $this->key = $key;
    }

    /** Forget the key, once the request it authenticated is answered. */
    public function clear(): void
    {
        $this->key = null;
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
