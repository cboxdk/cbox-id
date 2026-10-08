<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Console\ConsolePlane;
use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * WHOSE AUTHORITY a role or permission write is made with — the one question the role and
 * permission actions ask before anything else, answered from the principal and nothing
 * else.
 *
 * Two answers:
 *
 *  - THE ENVIRONMENT's (a management key, or a person on the environment console): every
 *    role in the environment may be changed, an environment-wide role may be defined, and
 *    every permission an app declared may be composed into a role.
 *  - ONE TENANT's (a person on an organization's own console): only that organization's
 *    own roles may be changed — an environment-owned role is assignable inside every
 *    tenant, so re-permissioning it would grant access in organizations that are not
 *    theirs — and only what an app in their reach marked `tenant_assignable` may be
 *    composed in.
 *
 * Never which organization a console page happens to be looking at: the organization a
 * role belongs to is an INPUT, and this only says which ones the caller may name.
 */
final readonly class RoleAuthority
{
    /**
     * @param  string|null  $tenant  The one organization the caller administers, or null for the environment's authority.
     */
    private function __construct(public ?string $tenant) {}

    public static function of(Principal $principal): self
    {
        if ($principal instanceof ConsoleSessionPrincipal && $principal->scope()->plane() === ConsolePlane::Organization) {
            return new self($principal->scope()->requireOrganizationId());
        }

        return new self(null);
    }

    /**
     * The roles this authority may WRITE to, as a query — so a write resolves its target
     * INSIDE the gate rather than checking afterwards. Another environment's role is not in
     * it either: the model is environment-scoped.
     *
     * @return Builder<Role>
     */
    public function changeable(): Builder
    {
        return $this->tenant === null
            ? Role::query()
            : Role::query()->whereNotNull('organization_id')->where('organization_id', $this->tenant);
    }

    /**
     * A role this authority may change, or a 404 — and an app-declared role refused once,
     * for every write, as forbidden whoever asks: the declaring app is its source of truth.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public function writable(string $roleId): Role
    {
        $role = $this->changeable()->whereKey($roleId)->first() ?? throw ActionRefused::notFound('role');

        if ($role->source === RoleSource::Manifest) {
            throw new AuthorizationException('This role is declared by an application, which is its source of truth. Change it in the app\'s manifest.');
        }

        return $role;
    }

    /**
     * The organization the role service fences a write on: null with the environment's
     * authority, the tenant otherwise — belt and braces alongside {@see self::changeable()},
     * so a later loosening of the lookup cannot quietly reopen a cross-tenant write.
     */
    public function fence(): ?string
    {
        return $this->tenant;
    }

    /**
     * client_id => name for the apps a role owned by `$organizationId` may be scoped to: the
     * environment's own apps plus that organization's, or every app for an environment-wide
     * role.
     *
     * @return array<string, string>
     */
    public function usableApps(?string $organizationId): array
    {
        $apps = [];

        $clients = Client::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $q): Builder => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId)
            ))
            ->orderBy('name')
            ->get(['client_id', 'name']);

        foreach ($clients as $client) {
            $apps[$client->client_id] = $client->name;
        }

        return $apps;
    }

    /**
     * The permissions this authority may put on a role: every live one with the
     * environment's authority; for a tenant, only what an app in its reach marked
     * `tenant_assignable` (an app keeps its privileged internal keys off the list by not
     * marking them), plus the unscoped ones it can see.
     *
     * @return Builder<Permission>
     */
    public function assignablePermissions(): Builder
    {
        $query = Permission::query()->whereNull('orphaned_at');

        if ($this->tenant === null) {
            return $query;
        }

        // Visible to the tenant as well: its own manual keys and the shared tier, never a
        // PEER's private `feature:action` — which names what that peer bought.
        return $query
            ->visibleToOrganization($this->tenant)
            ->where('tenant_assignable', true)
            ->where(fn (Builder $q): Builder => $q->whereIn('client_id', array_keys($this->usableApps($this->tenant)))->orWhereNull('client_id'));
    }
}
