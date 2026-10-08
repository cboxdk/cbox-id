<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\AuthenticateWorkspaceApi;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\RootPersonPrincipal;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Models\OrganizationApiKey;

/**
 * The credential authenticated for the current request on the global workspace plane: a
 * workspace key — the machine equivalent of a member's session — or a MEMBER of the team
 * through a token the platform root issued them ({@see RootPersonPrincipal}).
 *
 * Deliberately NOT environment-scoped: the workspace operates above every environment it
 * owns. Bound per-request (scoped) and populated by {@see AuthenticateWorkspaceApi}. At
 * most one of the two is set; {@see key()} stays the key alone.
 */
final class WorkspaceApiContext
{
    private ?OrganizationApiKey $key = null;

    private ?RootPersonPrincipal $person = null;

    public function set(OrganizationApiKey $key): void
    {
        $this->key = $key;
        $this->person = null;
    }

    public function setPerson(RootPersonPrincipal $person): void
    {
        $this->person = $person;
        $this->key = null;
    }

    /** Forget the credential, once the request it authenticated is answered. */
    public function clear(): void
    {
        $this->key = null;
        $this->person = null;
    }

    /** Whoever this request acts as on the workspace plane, in the action layer's terms. */
    public function principal(): ?Principal
    {
        return $this->key !== null ? new WorkspaceKeyPrincipal($this->key) : $this->person;
    }

    public function key(): ?OrganizationApiKey
    {
        return $this->key;
    }

    public function organizationId(): ?string
    {
        return $this->key->organization_id ?? $this->person?->workspace()?->id;
    }

    public function role(): ?MembershipRole
    {
        return $this->key?->role;
    }
}
