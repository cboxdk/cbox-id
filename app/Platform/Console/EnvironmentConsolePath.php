<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\BindOrganizationFromPath;
use App\Platform\Organizations\OrganizationLinks;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Where an environment's console lives on the app host, and back again.
 *
 * `orgs/{organization}/projects/{project}/environments/{environment}` for a customer's
 * environment, by slug — the organization by the same address the organization console
 * at `/orgs/{org}` uses ({@see OrganizationLinks::addressOf()}): the linked `cbox`
 * organization's slug, or its own when it is unlinked; `admin/environment` for the platform root's own, which belongs to
 * no organization and is administered by the deployment's operators.
 *
 * Both directions read the same rows, so a path names exactly one environment and an
 * environment has exactly one path. A path whose organization, project or environment do
 * not belong together resolves to nothing — that is the 404 a guessed URL gets.
 */
readonly class EnvironmentConsolePath
{
    /** The route parameter every environment-console route carries in front. */
    public const PARAMETER = 'environmentPath';

    /** The platform root environment's console. */
    public const ROOT = 'admin/environment';

    /** What the parameter may hold: a customer's environment, or the root's. */
    public const PATTERN = 'orgs/[^/]+/projects/[^/]+/environments/[^/]+|admin/environment';

    public function __construct(
        private PlatformRoot $root,
        private OrganizationLinks $links,
    ) {}

    /** The console path of an environment, or null when it has none (no project). */
    public function for(string $environmentId): ?string
    {
        return $this->root->run(function () use ($environmentId): ?string {
            $environment = Environment::query()->whereKey($environmentId)->first(['id', 'slug', 'project_id', 'is_default']);

            if ($environment === null) {
                return null;
            }

            if ((bool) $environment->is_default) {
                return self::ROOT;
            }

            $project = is_string($environment->project_id)
                ? Project::query()->whereKey($environment->project_id)->first(['id', 'slug', 'organization_id'])
                : null;

            $organizationSlug = $project !== null ? ($this->links->addressOf((string) $project->organization_id)['slug'] ?? null) : null;

            if ($project === null || $organizationSlug === null) {
                return null;
            }

            return 'orgs/'.$organizationSlug.'/projects/'.$project->slug.'/environments/'.$environment->slug;
        });
    }

    /**
     * The environment a console path names, or null when the parts do not belong together.
     */
    public function resolve(string $path): ?Environment
    {
        if ($path === self::ROOT) {
            return $this->root->run(fn (): ?Environment => Environment::query()->where('is_default', true)->first());
        }

        if (preg_match('#\Aorgs/([^/]+)/projects/([^/]+)/environments/([^/]+)\z#', $path, $parts) !== 1) {
            return null;
        }

        [, $organizationSlug, $projectSlug, $environmentSlug] = $parts;

        return $this->root->run(function () use ($organizationSlug, $projectSlug, $environmentSlug): ?Environment {
            // Read the way `/orgs/{org}` reads it ({@see BindOrganizationFromPath}), so the
            // two consoles never name one organization by two slugs.
            $organizationIds = $this->links->candidatesFor($organizationSlug);

            if ($organizationIds === []) {
                return null;
            }

            $projectId = Project::query()
                ->whereIn('organization_id', $organizationIds)
                ->where('slug', $projectSlug)
                ->value('id');

            if (! is_string($projectId)) {
                return null;
            }

            return Environment::query()
                ->where('slug', $environmentSlug)
                ->where('project_id', $projectId)
                ->where('is_default', false)
                ->first();
        });
    }

    /**
     * A URL on an environment's console for a page that is not the one being served — the
     * project page's "Open", the environment switcher, a link in a mail.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function route(string $environmentId, string $name = 'environment.home', array $parameters = [], bool $absolute = true): ?string
    {
        $path = $this->for($environmentId);

        return $path === null ? null : route($name, [self::PARAMETER => $path, ...$parameters], $absolute);
    }
}
