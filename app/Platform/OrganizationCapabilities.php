<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Organization\Enums\MembershipRole;

/** Native product app permissions; shared membership tiers are not product roles. */
final readonly class OrganizationCapabilities
{
    /** @param list<string> $permissions */
    private function __construct(private bool $owner, private array $permissions) {}

    /** @param list<string> $permissions */
    public static function fromAppPermissions(bool $owner, array $permissions): self
    {
        return new self($owner, array_values(array_unique($permissions)));
    }

    /** Legacy callers fail closed: only current shared ownership implies native authority. */
    public static function of(MembershipRole $role): self
    {
        return new self($role === MembershipRole::Owner, []);
    }

    /** The role stamped on an explicitly issued machine credential, never a human membership. */
    public static function forMachineRole(MembershipRole $role): self
    {
        return new self($role === MembershipRole::Owner, match ($role) {
            MembershipRole::Owner, MembershipRole::Admin => ['id:read', 'id:write', 'members:read', 'members:manage', 'billing:read', 'billing:manage'],
            MembershipRole::Developer => ['id:read', 'id:write'],
            MembershipRole::Viewer => ['id:read', 'members:read'],
            MembershipRole::Member => ['id:read'],
        });
    }

    public function allows(string $permission): bool
    {
        return $this->owner || in_array($permission, $this->permissions, true);
    }

    public function any(): bool
    {
        return $this->owner || $this->permissions !== [];
    }

    public function isOwner(): bool
    {
        return $this->owner;
    }

    public function canManageMembers(): bool
    {
        return $this->allows('members:manage');
    }

    public function canReadMembers(): bool
    {
        return $this->allows('members:read');
    }

    public function canReadEnvironments(): bool
    {
        return $this->allows('id:read');
    }

    public function canManageEnvironments(): bool
    {
        return $this->allows('id:write');
    }

    public function canReadBilling(): bool
    {
        return $this->allows('billing:read') || $this->allows('billing:manage');
    }

    public function canManageBilling(): bool
    {
        return $this->allows('billing:manage');
    }

    /** The maximum environment authority; row selection must still permit the environment. */
    public function environmentRole(): ?MembershipRole
    {
        return match (true) {
            $this->owner => MembershipRole::Owner,
            $this->canManageEnvironments() && $this->canManageMembers() => MembershipRole::Admin,
            $this->canManageEnvironments() => MembershipRole::Developer,
            $this->canReadEnvironments() => MembershipRole::Viewer,
            default => null,
        };
    }

    /** Only for legacy presentation or credentials already guarded by explicit capabilities. */
    public function role(): MembershipRole
    {
        return $this->environmentRole() ?? MembershipRole::Member;
    }
}
