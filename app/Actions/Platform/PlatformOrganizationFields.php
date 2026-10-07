<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Http\Resources\Environment\Timestamp;
use Cbox\Id\Organization\Models\Organization;

/**
 * An organization as the operator API returns it — `PlatformOrganization` in the spec: a
 * customer workspace in the platform root, or a tenant organization inside an environment.
 * A helper, not an action.
 */
final class PlatformOrganizationFields
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'type' => $organization->type->value,
            'status' => $organization->status->value,
            'parent_id' => $organization->parent_id,
            'environment_id' => $organization->getAttribute('environment_id'),
            'created_at' => Timestamp::of($organization->getAttribute('created_at')),
        ];
    }
}
