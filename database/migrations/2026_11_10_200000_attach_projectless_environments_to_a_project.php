<?php

declare(strict_types=1);

use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Contracts\Projects;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every environment but the platform root's belongs to a project.
 *
 * The environment console lives at `/orgs/{org}/projects/{project}/environments/{env}` on the
 * app host, and an environment with no project has no such address. `project_id` is
 * nullable ("owned by nobody"), so a row from before projects existed may still have none.
 *
 * Each one is attached to a project of the ONE organization that grants access to it
 * (`membership_environments`): that organization's oldest project, or a new one named after
 * it when it has none. An environment no organization grants, or more than one does, is not
 * guessed at — it is left as it is and logged, for a person to decide.
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

        foreach ($orphans as $environment) {
            $owners = DB::table('membership_environments')
                ->join('memberships', 'memberships.id', '=', 'membership_environments.membership_id')
                ->where('membership_environments.environment_id', $environment->id)
                ->distinct()
                ->pluck('memberships.organization_id')
                ->all();

            if (count($owners) !== 1) {
                Log::warning('An environment without a project was left as it is: no single organization grants it.', [
                    'environment' => $environment->id,
                    'slug' => $environment->slug,
                    'organizations' => count($owners),
                ]);

                continue;
            }

            $organizationId = $owners[0];

            if (! is_string($organizationId)) {
                continue;
            }

            $name = DB::table('organizations')->where('id', $organizationId)->value('name');
            $project = app(OrganizationProjects::class)->forOrganization($organizationId)->first()
                ?? app(Projects::class)->createForOrganization($organizationId, is_string($name) && $name !== '' ? $name : 'Default');

            DB::table('environments')->where('id', $environment->id)->update(['project_id' => $project->id]);
        }
    }

    public function down(): void
    {
        // Forward only (see above).
    }
};
