<?php

declare(strict_types=1);

namespace App\Platform\Shell\Levels;

use App\Actions\OrgPlane\Projects\CreateProject;
use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\Shell\InlineCreate;
use App\Platform\Shell\ShellRequest;
use Cbox\Console\Kit\Contracts\ContextLevel;
use Cbox\Console\Kit\Contracts\CreatesContextItems;
use Cbox\Console\Kit\Shell\ContextItem;
use Cbox\Console\Kit\Shell\ContextPage;
use Cbox\Console\Kit\Shell\ContextQuery;
use Cbox\Console\Kit\Shell\CreatedContextItem;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * THE PROJECT LEVEL: the organization's projects the person can reach.
 *
 * Somebody who manages environments sees every project, an empty one included, so they can
 * add its first environment. Somebody limited to some environments sees only the projects
 * that hold one of them ({@see NativeEnvironmentAccess}), the same rule the Projects page and
 * the environment console's door keep.
 */
final class IdProjectLevel implements ContextLevel, CreatesContextItems
{
    private const SLUGS = 'cbox.shell.project-slugs';

    public function key(): string
    {
        return 'project';
    }

    public function label(): string
    {
        return Str::ucfirst(__('console.project_244210e4'));
    }

    /**
     * The project is carried in the environment console's one path parameter, as part of
     * `orgs/{org}/projects/{project}/environments/{environment}`. Naming that parameter lets a
     * switch from the cascade (project and environment at once) keep the page an environment
     * console is on; the environment level, which comes after, supplies the whole path. A
     * project's own page (`orgs.projects.show`) shows one record and falls back to the list.
     */
    public function routeParameter(): string
    {
        return EnvironmentConsolePath::PARAMETER;
    }

    public function current(Request $request, array $ancestors): ?ContextItem
    {
        $organization = $ancestors[0] ?? null;

        if (! $organization instanceof ContextItem) {
            return null;
        }

        $id = $request->route()?->parameter('project');
        $slug = ShellRequest::projectSlug($request);

        foreach ($this->projects($organization->id) as $project) {
            if ((is_string($id) && $project->id === $id) || ($slug !== null && $project->slug === $slug)) {
                return self::item($project);
            }
        }

        return null;
    }

    public function find(Request $request, string $id, array $ancestors): ?ContextItem
    {
        $organization = $ancestors[0] ?? null;

        if (! $organization instanceof ContextItem) {
            return null;
        }

        foreach ($this->projects($organization->id) as $project) {
            if ($project->id === $id) {
                return self::item($project);
            }
        }

        return null;
    }

    public function list(Request $request, ContextQuery $query): ContextPage
    {
        $organization = $query->ancestors[0] ?? null;

        if (! $organization instanceof ContextItem) {
            return new ContextPage;
        }

        $term = mb_strtolower(trim((string) $query->search));
        $items = [];

        foreach ($this->projects($organization->id) as $project) {
            if ($term === '' || str_contains(mb_strtolower((string) $project->name), $term)) {
                $items[] = self::item($project);
            }
        }

        return IdOrganizationLevel::page($items, $query);
    }

    public function url(ContextItem $item, array $ancestors): string
    {
        return route('orgs.projects.show', ['org' => $ancestors[0]->routeKey ?? '', 'project' => $item->routeKey], false);
    }

    public function allUrl(array $ancestors): string
    {
        return route('orgs.projects', ['org' => $ancestors[0]->routeKey ?? ''], false);
    }

    public function settingsUrl(ContextItem $item, array $ancestors): string
    {
        return $this->url($item, $ancestors);
    }

    public function canCreate(Request $request, array $ancestors): bool
    {
        $organization = $ancestors[0] ?? null;

        return $organization instanceof ContextItem && app(InlineCreate::class)->capabilities($organization->id)?->canManageEnvironments() === true;
    }

    public function create(Request $request, string $name, array $ancestors): CreatedContextItem
    {
        $organization = $ancestors[0] ?? null;
        abort_unless($organization instanceof ContextItem && $this->canCreate($request, $ancestors), 403);

        $project = app(InlineCreate::class)->run($organization->id, CreateProject::class, ['name' => $name])->value;
        abort_unless($project instanceof Project, 500);

        return new CreatedContextItem((string) $project->id, $this->url(self::item($project), $ancestors).'#finish');
    }

    /** @return list<Project> */
    private function projects(string $organizationId): array
    {
        $subject = ShellRequest::subjectId();

        if ($subject === null) {
            return [];
        }

        $capabilities = app(InlineCreate::class)->capabilities($organizationId);

        if ($capabilities?->canReadEnvironments() !== true) {
            return [];
        }

        $all = $capabilities->canManageEnvironments();
        $accessible = $all ? [] : app(NativeEnvironmentAccess::class)->ids($organizationId, $subject);

        if (! $all && $accessible === []) {
            return [];
        }

        $projects = app(PlatformRoot::class)->run(static function () use ($organizationId, $all, $accessible): array {
            $query = Project::query()->where('organization_id', $organizationId)->orderBy('name');

            if (! $all) {
                $query->whereIn('id', Environment::query()->whereIn('id', $accessible)->select('project_id'));
            }

            return array_values($query->get(['id', 'name', 'slug', 'organization_id'])->all());
        }) ?? [];

        $slugs = [];

        foreach ($projects as $project) {
            $slugs[$organizationId.'|'.$project->id] = (string) $project->slug;
        }

        request()->attributes->set(self::SLUGS, array_merge(self::slugs(), $slugs));

        return $projects;
    }

    /**
     * The slug of a project this level listed in this request, so the environment level
     * builds its paths without reading the project again; null when it was not listed.
     */
    public static function slugOf(string $organizationId, string $projectId): ?string
    {
        return self::slugs()[$organizationId.'|'.$projectId] ?? null;
    }

    /** @return array<string, string> */
    private static function slugs(): array
    {
        $slugs = request()->attributes->get(self::SLUGS);

        return is_array($slugs) ? array_filter($slugs, static fn (mixed $slug, mixed $key): bool => is_string($slug) && is_string($key), ARRAY_FILTER_USE_BOTH) : [];
    }

    private static function item(Project $project): ContextItem
    {
        return new ContextItem(id: (string) $project->id, name: (string) $project->name);
    }
}
