<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;

/**
 * The organization the signed-in person is currently acting FOR — not every organization
 * they belong to, and not the one that owns the page they are looking at.
 *
 * `role` is the membership role in THIS organization, and it is the only authority a
 * React page is given about what the person may do here. Everything stronger — whether
 * they administer the environment, whether they are a platform operator — is answered by
 * {@see ScopeProps}, because those are questions about a different
 * subject entirely.
 */
final readonly class OrganizationProps implements Prop
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $slug,
        public ?MembershipRole $role,
    ) {}

    public static function from(Organization $organization, ?MembershipRole $role): self
    {
        return new self(
            id: $organization->id,
            name: $organization->name,
            slug: is_string($organization->slug ?? null) ? $organization->slug : null,
            role: $role,
        );
    }

    /**
     * @return array{id: string, name: string, slug: string|null, role: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'role' => $this->role?->value,
        ];
    }
}
