<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Resolves {@see EnvironmentLineage} for a set of environments in a FIXED number of
 * queries — two, plus whatever locating the platform root costs, regardless of how many
 * environments were handed in.
 *
 * Written as a batch resolver rather than as a relation walk on purpose. Every place
 * that needs this renders a list: the platform environments table, the target switcher
 * in the console chrome, and the account detail page. `$environment->account->name` in
 * any of those is one query per row on a page whose whole job is to show every plane on
 * the install — the exact shape the console's query-budget test exists to catch, and the
 * shape the accounts page already avoids with its `selectRaw ... groupBy` counts.
 *
 * Accounts, projects and environments all sit ABOVE the tenancy boundary, so none of
 * these reads needs a scope escape and none of them can see into a tenant.
 */
final readonly class EnvironmentLineages
{
    public function __construct(private PlatformRoot $root) {}

    /**
     * Lineage for each environment, keyed by environment id.
     *
     * Takes the environments the caller has ALREADY loaded rather than re-reading them:
     * every caller is rendering those exact rows, and a resolver that fetched its own
     * would double the cost of the page it exists to make cheap.
     *
     * @param  iterable<int|string, Environment>  $environments
     * @return array<string, EnvironmentLineage>
     */
    public function for(iterable $environments): array
    {
        /** @var list<Environment> $rows */
        $rows = [];
        /** @var array<string, true> $projectIds */
        $projectIds = [];

        foreach ($environments as $environment) {
            $rows[] = $environment;

            $projectId = $this->projectIdOf($environment);

            if ($projectId !== null) {
                $projectIds[$projectId] = true;
            }
        }

        // OWNERSHIP RUNS THROUGH THE PROJECT. `environments.account_id` was a denormalized
        // copy of it and is gone, so the owning organization is read from the project — one
        // batched lookup for the whole page rather than a per-row walk, which is the same
        // property the account column bought and the reason it was tempting.
        $projectOwners = $this->projectOwners(array_keys($projectIds));
        $organizationNames = $this->organizationNames(array_values(array_unique($projectOwners)));
        $projectNames = $this->projectNames(array_keys($projectIds));

        // Asked ONCE for the whole batch, and asked of PlatformRoot rather than read off
        // `is_default` here: that class is the single answer to "which environment is the
        // platform's own", and it also honours a deployment that pins its root by config
        // instead of stamping the row.
        $rootId = $this->root->environment()?->environmentKey();

        $lineages = [];

        foreach ($rows as $environment) {
            $projectId = $this->projectIdOf($environment);
            $organizationId = $projectId === null ? null : ($projectOwners[$projectId] ?? null);

            $lineages[$environment->id] = new EnvironmentLineage(
                environmentId: $environment->id,
                organizationId: $organizationId,
                organizationName: $organizationId === null ? null : ($organizationNames[$organizationId] ?? null),
                projectId: $projectId,
                projectName: $projectId === null ? null : ($projectNames[$projectId] ?? null),
                isPlatformRoot: $rootId !== null && $rootId === $environment->id,
            );
        }

        return $lineages;
    }

    /** Lineage for one environment — the same batch of two, for a page that shows one. */
    public function forOne(Environment $environment): EnvironmentLineage
    {
        return $this->for([$environment])[$environment->id]
            ?? new EnvironmentLineage(environmentId: $environment->id);
    }

    /**
     * Read off the attribute bag and narrowed rather than reached for as a property: the
     * caller may have selected a subset of columns, and a missing attribute must answer
     * null rather than raise. Same treatment the console gives the undeclared timestamp
     * columns.
     */
    private function projectIdOf(Environment $environment): ?string
    {
        $projectId = $environment->getAttribute('project_id');

        return is_string($projectId) && $projectId !== '' ? $projectId : null;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function organizationNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];

        // IN THE PLATFORM ROOT: `organizations` is environment-owned, and this runs on
        // pages that may be standing on any host. `accounts` sat outside tenancy, so the
        // read this replaces needed no scope at all.
        $organizations = $this->root->run(
            fn () => Organization::query()->whereKey($ids)->get(['id', 'name']),
        );

        foreach ($organizations ?? [] as $organization) {
            $names[$organization->id] = $organization->name;
        }

        return $names;
    }

    /**
     * project id => owning organization id, for the whole batch at once.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function projectOwners(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> */
        return Project::query()->whereKey($ids)->pluck('organization_id', 'id')->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function projectNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (Project::query()->whereKey($ids)->get(['id', 'name']) as $project) {
            $names[$project->id] = $project->name;
        }

        return $names;
    }
}
