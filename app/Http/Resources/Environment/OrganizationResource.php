<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\Organization\Models\Organization;

/**
 * An organization on the environment management API — `Organization` in
 * `resources/openapi/environment.yaml`.
 *
 * The resource classes in this namespace are the one place each object's wire shape is
 * written, so a list and the create that returns the same object cannot drift apart.
 */
final class OrganizationResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'type' => $organization->type->value,
            'status' => $organization->status->value,
            'parent_id' => $organization->parent_id,
            'created_at' => Timestamp::of($organization->getAttribute('created_at')),
        ];
    }
}
