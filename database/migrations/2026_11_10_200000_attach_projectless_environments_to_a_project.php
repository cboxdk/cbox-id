<?php

declare(strict_types=1);

use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Contracts\Projects;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every environment but the platform root's belongs to a project.
 *
 * The environment console lives at `/orgs/{org}/projects/{project}/environments/{env}` on the
 * app host, and an environment with no project has no such address. `project_id` is
 * nullable ("owned by nobody"), so a row from before projects existed may still have none.
 *
 * Each one is attached to a project of the ONE organization whose ACTIVE memberships grant
 * access to it (`membership_environments`): that organization's oldest project, or a new one
 * named after it when it has none. An environment no organization grants, or more than one
 * does, is not guessed at: the migration FAILS, naming each one, before it writes anything,
 * so the deploy stops rather than leave an environment without a console nobody notices.
 * A person decides each such row (gives it a project), and the deploy runs again. Run
 * `SELECT id, slug FROM environments WHERE project_id IS NULL AND is_default = false` on
 * production before this merges, so that decision is made before the deploy, not during it.
 *
 * Data only, and forward only: `down()` does not detach anything, because the console
 * addresses that would vanish are already in people's bookmarks.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('environments')
            ->whereNull('project_id')
            ->where('is_default', false)
            ->get(['id', 'slug']);

        $placements = [];
        $unplaced = [];

        foreach ($orphans as $environment) {
            $environmentId = is_scalar($environment->id) ? (string) $environment->id : '';
            $slug = is_scalar($environment->slug) ? (string) $environment->slug : '';

            $owners = DB::table('membership_environments')
                ->join('memberships', 'memberships.id', '=', 'membership_environments.membership_id')
                ->where('membership_environments.environment_id', $environmentId)
                // Only a membership that is in force speaks for its organization: an
                // invitation not yet accepted, or a suspended member, owns nothing.
                ->where('memberships.status', 'active')
                ->distinct()
                ->pluck('memberships.organization_id')
                ->all();

            $organizationId = $owners[0] ?? null;

            if (count($owners) !== 1 || ! is_string($organizationId)) {
                $unplaced[] = sprintf('%s (%s, granted by %d organizations)', $environmentId, $slug, count($owners));

                continue;
            }

            $placements[$environmentId] = $organizationId;
        }

        if ($unplaced !== []) {
            throw new RuntimeException(
                'Environments without a project that no single organization grants; give each a project, then migrate again: '.implode(', ', $unplaced),
            );
        }

        foreach ($placements as $environmentId => $organizationId) {
            $name = DB::table('organizations')->where('id', $organizationId)->value('name');
            $project = app(OrganizationProjects::class)->forOrganization($organizationId)->first()
                ?? app(Projects::class)->createForOrganization($organizationId, is_string($name) && $name !== '' ? $name : 'Default');

            DB::table('environments')->where('id', $environmentId)->update(['project_id' => $project->id]);
        }
    }

    public function down(): void
    {
        // Forward only (see above).
    }
};
