<?php

declare(strict_types=1);

namespace App\Http\Resources\Workspace;

use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;

/**
 * A project (one IdP product, its own billing anchor) — `Project` in the workspace spec.
 * `environments_used` is counted live, beside the allowance it is measured against.
 */
final class ProjectResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'status' => $project->status->value,
            'environment_limit' => $project->environment_limit,
            'environments_used' => Environment::query()->where('project_id', $project->id)->count(),
        ];
    }
}
