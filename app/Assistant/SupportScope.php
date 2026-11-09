<?php

declare(strict_types=1);

namespace App\Assistant;

use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** What one support principal may see of its workspace: {@see SupportContext::scope()}. */
final readonly class SupportScope
{
    /**
     * @param  Collection<int, Project>  $projects
     * @param  Builder<Environment>  $environments
     */
    public function __construct(public Collection $projects, private Builder $environments) {}

    /** @return Builder<Environment> a fresh query each call, so callers can narrow it */
    public function environments(): Builder
    {
        return clone $this->environments;
    }
}
