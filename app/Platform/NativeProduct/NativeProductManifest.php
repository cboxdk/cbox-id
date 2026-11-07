<?php

declare(strict_types=1);

namespace App\Platform\NativeProduct;

use Cbox\Id\AccessControl\Manifest\Manifest;
use Cbox\Id\AccessControl\Manifest\ManifestParser;

/** The native ID console is an app of the root identity authority. Definitions grant nobody access. */
final class NativeProductManifest
{
    public const array PERMISSIONS = ['id:read', 'id:write', 'members:read', 'members:manage', 'billing:read', 'billing:manage'];

    public const array ROLES = [
        'viewer' => ['name' => 'Reader', 'permissions' => ['id:read', 'members:read']],
        'developer' => ['name' => 'Editor', 'permissions' => ['id:read', 'id:write']],
        'billing' => ['name' => 'Billing', 'permissions' => ['billing:read', 'billing:manage']],
        'admin' => ['name' => 'Admin', 'permissions' => self::PERMISSIONS],
    ];

    public function manifest(): Manifest
    {
        return (new ManifestParser)->parse([
            'version' => '1',
            'permissions' => array_map(static fn (string $key): array => ['key' => $key, 'name' => match ($key) {
                'id:read' => 'Read ID projects and environments',
                'id:write' => 'Manage ID projects and environments',
                'members:read' => 'Read the ID product team',
                'members:manage' => 'Manage ID product access',
                'billing:read' => 'Read ID billing',
                'billing:manage' => 'Manage ID billing',
            }, 'tenant_assignable' => true], self::PERMISSIONS),
            'roles' => array_map(static fn (string $key, array $role): array => ['key' => $key, ...$role], array_keys(self::ROLES), array_values(self::ROLES)),
        ]);
    }
}
