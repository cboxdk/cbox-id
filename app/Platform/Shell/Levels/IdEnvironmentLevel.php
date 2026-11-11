<?php

declare(strict_types=1);

namespace App\Platform\Shell\Levels;

use App\Actions\OrgPlane\Environments\CreateEnvironment;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\Shell\InlineCreate;
use App\Platform\Shell\ShellRequest;
use Cbox\Console\Kit\Contracts\ContextLevel;
use Cbox\Console\Kit\Contracts\CreatesContextItems;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Console\Kit\Shell\ContextItem;
use Cbox\Console\Kit\Shell\ContextPage;
use Cbox\Console\Kit\Shell\ContextQuery;
use Cbox\Console\Kit\Shell\CreatedContextItem;
use Cbox\Console\Kit\Shell\Tone;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * THE ENVIRONMENT LEVEL: a project's environments the person may administer.
 *
 * Its route parameter is the environment console's one `{environmentPath}`
 * (`orgs/{org}/projects/{project}/environments/{environment}`), so an item's route key is
 * that whole path: switching environment rebuilds the same page under the new path, and a
 * record page falls back to its list ({@see EnvironmentConsolePath}).
 *
 * A sandbox carries test data (its mail is suppressed), so the sandbox strip shows there.
 * Production comes first, then the rest by name.
 */
final class IdEnvironmentLevel implements ContextLevel, CreatesContextItems
{
    public function key(): string
    {
        return 'environment';
    }

    public function label(): string
    {
        return __('console.environment_9e471951');
    }

    public function routeParameter(): string
    {
        return EnvironmentConsolePath::PARAMETER;
    }

    public function current(Request $request, array $ancestors): ?ContextItem
    {
        $slug = ShellRequest::environmentPathParts($request)[2] ?? null;

        if ($slug === null) {
            return null;
        }

        foreach ($this->environments($ancestors) as $item) {
            if (str_ends_with($item->routeKey, '/environments/'.$slug)) {
                return $item;
            }
        }

        return null;
    }

    public function find(Request $request, string $id, array $ancestors): ?ContextItem
    {
        foreach ($this->environments($ancestors) as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    public function list(Request $request, ContextQuery $query): ContextPage
    {
        $term = mb_strtolower(trim((string) $query->search));

        return IdOrganizationLevel::page(array_values(array_filter(
            $this->environments($query->ancestors),
            static fn (ContextItem $item): bool => $term === '' || str_contains(mb_strtolower($item->name), $term),
        )), $query);
    }

    public function url(ContextItem $item, array $ancestors): string
    {
        $twin = self::twinOfSwitchOrigin();

        if ($twin !== null && self::servedIn($twin, $item->id)) {
            return route($twin, [EnvironmentConsolePath::PARAMETER => $item->routeKey], false);
        }

        return route('environment.home', [EnvironmentConsolePath::PARAMETER => $item->routeKey], false);
    }

    /**
     * Opening an environment from an organization page opens that page's twin there, by the
     * console's naming rule (`orgs.settings.api-keys` → `environment.keys`,
     * {@see ConsoleScope::environmentTwin()}): Workspace › Keys is the same capability as an
     * environment's API keys. The kit keeps the page only for a route that already carries
     * the environment, so the switch's `from` is read here. Null where there is no twin.
     */
    private static function twinOfSwitchOrigin(): ?string
    {
        $from = request()->input('from');

        if (! is_string($from) || ! str_starts_with($from, '/')) {
            return null;
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create($from));
        } catch (HttpExceptionInterface) {
            return null;
        }

        $name = $route->getName();

        if (! is_string($name) || in_array(EnvironmentConsolePath::PARAMETER, $route->parameterNames(), true)) {
            return null;
        }

        $twin = Route::getRoutes()->getByName(ConsoleScope::environmentTwin($name));

        return $twin !== null
            && $twin->parameterNames() === [EnvironmentConsolePath::PARAMETER]
            && in_array('GET', $twin->methods(), true) ? $twin->getName() : null;
    }

    /** Whether that environment serves the page: a module's page is there only where its feature is on. */
    private static function servedIn(string $route, string $environmentId): bool
    {
        $features = collect(Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [])
            ->filter(static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'console.feature:'))
            ->map(static fn (string $middleware): string => substr($middleware, strlen('console.feature:')));

        return $features->isEmpty() || app(EnvironmentContext::class)->runAs(
            GenericEnvironment::of($environmentId),
            static fn (): bool => $features->every(static fn (string $feature): bool => Console::featureActive($feature)),
        );
    }

    public function allUrl(array $ancestors): string
    {
        $organization = $ancestors[0] ?? null;
        $project = $ancestors[1] ?? null;

        return $organization instanceof ContextItem && $project instanceof ContextItem
            ? route('orgs.projects.show', ['org' => $organization->routeKey, 'project' => $project->routeKey], false)
            : route('orgs.index', [], false);
    }

    public function settingsUrl(ContextItem $item, array $ancestors): string
    {
        return route('environment.settings', [EnvironmentConsolePath::PARAMETER => $item->routeKey], false);
    }

    public function canCreate(Request $request, array $ancestors): bool
    {
        $organization = $ancestors[0] ?? null;

        return $organization instanceof ContextItem && ($ancestors[1] ?? null) instanceof ContextItem
            && app(InlineCreate::class)->capabilities($organization->id)?->canManageEnvironments() === true;
    }

    public function create(Request $request, string $name, array $ancestors): CreatedContextItem
    {
        abort_unless($this->canCreate($request, $ancestors), 403);

        [$organization, $project] = $ancestors;

        $environment = app(InlineCreate::class)->run($organization->id, CreateEnvironment::class, [
            'name' => $name,
            'type' => EnvironmentType::Sandbox->value,
            'project_id' => $project->id,
        ])->value;
        abort_unless($environment instanceof Environment, 500);

        foreach ($this->environments($ancestors) as $item) {
            if ($item->id === $environment->id) {
                return new CreatedContextItem($item->id, route('environment.get-started', [EnvironmentConsolePath::PARAMETER => $item->routeKey], false));
            }
        }

        abort(500);
    }

    /**
     * @param  list<ContextItem>  $ancestors  [organization, project]
     * @return list<ContextItem>
     */
    private function environments(array $ancestors): array
    {
        $organization = $ancestors[0] ?? null;
        $project = $ancestors[1] ?? null;
        $subject = ShellRequest::subjectId();

        if (! $organization instanceof ContextItem || ! $project instanceof ContextItem || $subject === null) {
            return [];
        }

        $accessible = app(NativeEnvironmentAccess::class)->ids($organization->id, $subject);

        if ($accessible === []) {
            return [];
        }

        $known = IdProjectLevel::slugOf($organization->id, $project->id);

        $rows = app(PlatformRoot::class)->run(static function () use ($organization, $project, $accessible, $known): array {
            $slug = $known ?? Project::query()->whereKey($project->id)->where('organization_id', $organization->id)->value('slug');

            if (! is_string($slug)) {
                return [];
            }

            return [$slug, array_values(Environment::query()
                ->where('project_id', $project->id)
                ->whereIn('id', $accessible)
                ->where('is_default', false)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'type'])
                ->all())];
        }) ?? [];

        if ($rows === []) {
            return [];
        }

        [$projectSlug, $environments] = $rows;

        usort($environments, static fn (Environment $a, Environment $b): int => [$a->type === EnvironmentType::Production ? 0 : 1, mb_strtolower((string) $a->name)]
            <=> [$b->type === EnvironmentType::Production ? 0 : 1, mb_strtolower((string) $b->name)]);

        return array_map(static function (Environment $environment) use ($organization, $projectSlug): ContextItem {
            $sandbox = $environment->type === EnvironmentType::Sandbox;

            return new ContextItem(
                id: $environment->id,
                name: (string) $environment->name,
                routeKey: 'orgs/'.$organization->routeKey.'/projects/'.$projectSlug.'/environments/'.$environment->slug,
                tone: $sandbox ? Tone::Sandbox : Tone::Production,
                label: __($sandbox ? 'console.sandbox_67fc6249' : 'console.production_13a7c655'),
                testData: $sandbox,
            );
        }, $environments);
    }
}
