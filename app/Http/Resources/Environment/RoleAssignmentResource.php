<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Models\Role;

/**
 * One role held by one person — inside an organization (`organization_id` set) or
 * everywhere in the environment (`organization_id` null, a staff grant). `source` says how
 * it got there: granted by hand, pushed by a directory, and so on.
 */
final class RoleAssignmentResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Role $role, string $userId, ?string $organizationId, GrantSource $source): array
    {
        return [
            'role_id' => $role->id,
            'key' => $role->key,
            'name' => $role->name,
            'client_id' => $role->client_id,
            'tenant_assignable' => $role->tenant_assignable,
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'source' => $source->value,
        ];
    }
}
