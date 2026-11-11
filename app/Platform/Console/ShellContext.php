<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\AuthenticateEnvironmentAdmin;
use App\Http\Props\Shell\ContextEnvironmentProps;
use App\Http\Props\Shell\ContextProjectProps;
use App\Http\Props\Shell\ShellContextProps;
use App\Http\Props\Shell\SwitchOptionProps;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Locale\ConsoleLabels;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\OrganizationCapabilities;
use App\Platform\Organizations\OrganizationLinks;
use App\Platform\PlaneResolver;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * WHERE THE PERSON IS, AND WHERE ELSE THEY CAN GO — the topbar's
 * `Workspace ▾ / Project ▾ / Environment ▾`.
 *
 * Before this the topbar answered the question three different ways depending on which
 * console drew it. The organization console had an organization switcher; an operator had
 * a second one re-pointing the console at any environment on the install; the environment
 * console had neither — a back arrow to the workspace and the environment's name as plain
 * text — so moving from staging to production meant going back to Projects on another
 * host and opening it again. One question, one control, on every console.
 *
 * WHAT IS LISTED IS WHAT MAY BE OPENED. Workspaces are the person's own memberships.
 * Projects and environments are the current workspace's, and only the environments this
 * person may ADMINISTER: the same two checks `/open/{environment}` makes before it mints
 * a handoff (the capability, and {@see Memberships::accessibleEnvironmentIds()}), asked
 * here so the menu never offers a door that answers 403. Another workspace's projects
 * are never listed, even for a person who belongs to it — they are one switch away, and
 * listing them would mean answering access questions about a workspace this console is
 * not acting in.
 *
 * READ IN THE PLATFORM ROOT on an environment's own host. Memberships and organizations
 * are rows of the root environment, and that host's ambient scope is the tenant's — read
 * there, the person simply has no memberships. Same reason the handoff's redemption reads
 * them in the root.
 *
 * THE PLATFORM'S OWN ENVIRONMENT, FOR THOSE WHO RUN IT. A platform operator also sees the
 * root environment — how every workspace owner and every operator signs in to Cbox ID — as
 * one more environment they administer, under a "Platform" project, on every console they
 * stand in: the workspace console at the root, platform admin, a tenant's environment
 * console, and the root's own (where it is the current one). Nobody else ever does; the
 * same question the root's console asks at its door ({@see EnvironmentAdminAuth::rootOperator()})
 * is asked here, so the menu never offers it to somebody it would refuse.
 *
 * COST. This runs on every console page. A single-workspace person on the organization
 * console costs one membership read (already paid by the shell before this existed); a
 * workspace adds one read of its projects, one of their environments and one access read
 * — a fixed number however many projects or environments there are.
 */
final readonly class ShellContext
{
    public function __construct(
        private ConsoleScope $scope,
        private CurrentUser $user,
        private Memberships $memberships,
        private Organizations $organizations,
        private OrganizationProjects $projects,
        private PlatformRoot $root,
        private EnvironmentContext $environments,
        private PlaneResolver $planes,
    ) {}

    /**
     * The organization console's context: the person's own organizations, and — where the
     * organization is a workspace on the account plane — its projects and environments.
     *
     * @param  string|null  $activeRoute  the nav page this request lights, for the landing page
     */
    public function forOrganizationPlane(?string $activeRoute): ShellContextProps
    {
        $subjectId = $this->user->id();
        $currentId = $this->user->organizationId();
        $memberships = $this->memberships->forUser($subjectId);

        $workspace = $this->scope->membershipRole() !== null;

        $projects = [];

        // Only on the ACCOUNT plane (the app host), where the environment consoles live. On a
        // customer's environment host the organization is a tenant and has none.
        if ($workspace && $currentId !== null && $this->planes->onAccountPlane()) {
            $to = $this->landingRoute($activeRoute, onEnvironmentPlane: false);

            $projects = $this->projectsOf(
                organizationId: $currentId,
                subjectId: $subjectId,
                capabilities: $this->scope->capabilities(),
                currentEnvironmentId: null,
                href: fn (string $path, Environment $environment): string => route($this->servedIn($to, $environment), [EnvironmentConsolePath::PARAMETER => $path]),
            );
        }

        // The platform's own environment, for an operator at the root.
        if ($this->planes->onAccountPlane() && $this->scope->isPlatformOperator()) {
            $projects[] = $this->platformProject(current: false, to: $this->landingRoute($activeRoute, onEnvironmentPlane: false));
        }

        return new ShellContextProps(
            noun: $workspace ? __('console.organization_d764d425') : __('console.organization_d764d425'),
            organizations: $this->organizationOptions($memberships, $currentId, fallbackName: $this->user->organization()?->name),
            switchUrl: Route::has('orgs.switch') ? route('orgs.switch') : null,
            projects: $projects,
        );
    }

    /**
     * The PLATFORM ROOT's environment as a row of the switcher — under a "Platform" project
     * of its own, opening its console (`/admin/environment/…`) at the same page where there
     * is one.
     */
    private function platformProject(bool $current, string $to): ContextProjectProps
    {
        $environment = $this->root->model();
        $href = route($environment === null ? $to : $this->servedIn($to, $environment), [EnvironmentConsolePath::PARAMETER => EnvironmentConsolePath::ROOT]);

        return new ContextProjectProps(
            id: 'platform',
            // The product's own name: the platform's environment is Cbox ID's sign-in.
            name: is_string($product = config('app.name')) && $product !== '' ? $product : 'Cbox ID',
            current: $current,
            environments: [new ContextEnvironmentProps(
                id: is_string($id = $environment?->getKey()) ? $id : 'platform',
                name: is_string($name = $environment?->name) && $name !== '' ? $name : __('console.platform_c78ffe19'),
                type: $environment?->type->value ?? 'production',
                current: $current,
                href: $href,
            )],
        );
    }

    /**
     * A console page on the WORKSPACE's host, for a link drawn on an environment console.
     *
     * The environment console is on the environment's own host, where the administrator
     * holds an environment binding and no subject session; the person's own pages — their
     * account, the signed-in-user switcher, the workspace's Projects — are on the host the
     * handoff came from. Same host derivation as the handoff's own refusal path
     * ({@see AuthenticateEnvironmentAdmin}), so the way out and the
     * way in agree.
     *
     * @param  array<string, string>  $parameters
     */
    public function onOrganizationHost(string $route, array $parameters = []): string
    {
        $host = $this->planes->consoleHost();

        // A page under `/orgs/{org}` names the organization this console belongs to:
        // the one the request is in, or, on an environment's console, the one whose
        // membership opened it. Neither known, it is the organization picker.
        if (str_starts_with($route, 'orgs.') && ! isset($parameters['org']) && ! isset(URL::getDefaultParameters()['org'])) {
            $organizationId = $this->user->organizationId() ?? app(EnvironmentAdminAuth::class)->membership()?->organization_id;
            $slug = is_string($organizationId) ? app(OrganizationLinks::class)->addressOf($organizationId)['slug'] ?? null : null;

            if ($slug === null) {
                $route = 'orgs.index';
                $parameters = [];
            } else {
                $parameters['org'] = $slug;
            }
        }

        return $host === null
            ? route($route, $parameters)
            : 'https://'.$host.route($route, $parameters, false);
    }

    /**
     * The page to land on in another environment, as an environment-console route name:
     * this page, if the environment console has it without a record in the URL; else the
     * page of the area this request lights (a user's detail page lands on Users); else the
     * environment's home page.
     *
     * The organization console's pages map by the console's own naming rule — `webhooks`
     * here is `environment.webhooks` there ({@see ConsoleScope::routeName()}) — so opening
     * an environment from the workspace's Webhooks page opens that environment's Webhooks.
     */
    private function landingRoute(?string $activeRoute, bool $onEnvironmentPlane): string
    {
        $current = request()->route()?->getName();

        foreach ([$current, $activeRoute] as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $candidate = $onEnvironmentPlane ? $name : ConsoleScope::environmentTwin($name);
            $route = Route::getRoutes()->getByName($candidate);

            if ($route !== null
                && $route->parameterNames() === [EnvironmentConsolePath::PARAMETER]
                && in_array('GET', $route->methods(), true)) {
                return $candidate;
            }
        }

        return 'environment.home';
    }

    /**
     * The landing route, if that environment serves it — else its home page.
     *
     * A module page is there only where its feature is on, and a feature can be on for one
     * environment and off for the next (a module's sink is configured per environment).
     * Asked IN that environment, so the switcher never offers a link that answers 404.
     */
    private function servedIn(string $to, Environment $environment): string
    {
        if ($to === 'environment.home') {
            return $to;
        }

        $features = collect(Route::getRoutes()->getByName($to)?->gatherMiddleware() ?? [])
            ->filter(static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'console.feature:'))
            ->map(static fn (string $middleware): string => substr($middleware, strlen('console.feature:')));

        if ($features->isEmpty()) {
            return $to;
        }

        $served = $this->environments->runAs($environment, static fn (): bool => $features->every(
            static fn (string $feature): bool => Console::featureActive($feature),
        ));

        return $served ? $to : 'environment.home';
    }

    /**
     * The person's organizations as switcher rows — ALWAYS including the current one, so
     * the crumb can name where you are even when there is nowhere else to go.
     *
     * One membership in the overwhelming majority of sessions, and that case costs no
     * organization read at all: its name is already on the signed-in session.
     *
     * @param  Collection<int, Membership>  $memberships
     * @param  (Closure(string): ?string)|null  $openHref  where a row opens, by organization id
     * @return list<SwitchOptionProps>
     */
    private function organizationOptions(
        Collection $memberships,
        ?string $currentId,
        ?string $fallbackName,
        ?Closure $openHref = null,
    ): array {
        // The organization's address and name as every Cbox app shows them: the linked
        // `cbox` organization's, where there is one.
        $addresses = app(OrganizationLinks::class);

        if ($memberships->count() <= 1 && $fallbackName !== null && $currentId !== null) {
            $membership = $memberships->first();

            return [new SwitchOptionProps(
                id: $currentId,
                label: $addresses->addressOf($currentId)['name'] ?? $fallbackName,
                caption: $membership === null ? null : ConsoleLabels::navigation($membership->role->label()),
                current: true,
                openHref: $openHref === null ? null : $openHref($currentId),
            )];
        }

        /** @var list<string> $ids */
        $ids = [];

        foreach ($memberships as $membership) {
            $ids[] = $membership->organization_id;
        }

        $organizations = $ids === [] ? [] : $this->organizations->findMany($ids);
        // Every row's address in one read, so nine organizations cost what one does.
        $addresses->addressesOf($ids);

        $options = [];

        foreach ($memberships as $membership) {
            $organization = $organizations[$membership->organization_id] ?? null;

            // A membership whose organization no longer resolves is not a row somebody
            // can switch to. Skipped rather than rendered blank.
            if ($organization === null) {
                continue;
            }

            $options[] = new SwitchOptionProps(
                id: $membership->organization_id,
                label: $addresses->addressOf($membership->organization_id)['name'] ?? $organization->name,
                caption: ConsoleLabels::navigation($membership->role->label()),
                current: $membership->organization_id === $currentId,
                openHref: $openHref === null ? null : $openHref($membership->organization_id),
            );
        }

        return $options;
    }

    /**
     * The workspace's projects, each with the environments this person may open.
     *
     * The two gates the console's path applies, in the same order: the role must be one
     * that manages environments at all (a Viewer reaches environments but is never handed
     * an admin token), and each environment must be among the member's accessible ones —
     * which narrows to the grants of a member scoped to a few. A project left with nothing
     * openable is dropped rather than listed empty.
     *
     * @param  callable(string, Environment): string  $href  the URL for an environment's console path
     * @return list<ContextProjectProps>
     */
    private function projectsOf(
        string $organizationId,
        string $subjectId,
        ?OrganizationCapabilities $capabilities,
        ?string $currentEnvironmentId,
        callable $href,
    ): array {
        if ($capabilities?->canReadEnvironments() !== true) {
            return [];
        }

        $accessible = app(NativeEnvironmentAccess::class)->ids($organizationId, $subjectId);

        if ($accessible === []) {
            return [];
        }

        /** @var Collection<int, Project> $owned */
        $owned = $this->projects->forOrganization($organizationId);

        if ($owned->isEmpty()) {
            return [];
        }

        // The organization's address at `/orgs/{org}` — its linked `cbox` slug — exactly as
        // {@see EnvironmentConsolePath} builds and resolves it.
        $organizationSlug = app(OrganizationLinks::class)->addressOf($organizationId)['slug'] ?? null;

        if (! is_string($organizationSlug) || $organizationSlug === '') {
            return [];
        }

        // ONE read for every environment across every project, grouped in memory — the
        // same shape as the Projects page, for the same reason.
        $environments = Environment::query()
            ->whereIn('project_id', $owned->pluck('id')->all())
            ->whereIn('id', $accessible)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type', 'project_id'])
            ->groupBy('project_id');

        $rows = [];

        foreach ($owned as $project) {
            /** @var Collection<int, Environment> $inProject */
            $inProject = $environments->get($project->id) ?? new Collection;

            if ($inProject->isEmpty()) {
                continue;
            }

            $options = [];
            $current = false;

            foreach ($inProject as $environment) {
                $isCurrent = $environment->id === $currentEnvironmentId;
                $current = $current || $isCurrent;

                $options[] = new ContextEnvironmentProps(
                    id: $environment->id,
                    name: $environment->name,
                    type: $environment->type->value,
                    current: $isCurrent,
                    href: $href('orgs/'.$organizationSlug.'/projects/'.$project->slug.'/environments/'.$environment->slug, $environment),
                );
            }

            $rows[] = new ContextProjectProps(
                id: $project->id,
                name: $project->name,
                current: $current,
                environments: $options,
            );
        }

        return $rows;
    }
}
