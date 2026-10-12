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
use App\Platform\OrganizationCapabilities;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

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
        private HandoffTarget $targets,
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

        // Only on the ACCOUNT plane, where `/open/{environment}` is the door to an
        // environment's own console. On a single-host install there is no second host to
        // hand off to, and on a customer's environment host the organization is a tenant.
        if ($workspace && $currentId !== null && $this->planes->onAccountPlane() && Route::has('environment.open')) {
            $to = $this->landingPath($activeRoute, onEnvironmentPlane: false);

            $projects = $this->projectsOf(
                organizationId: $currentId,
                subjectId: $subjectId,
                capabilities: $this->scope->capabilities(),
                currentEnvironmentId: null,
                href: fn (string $id): string => $this->withTarget(route('environment.open', $id), $to),
            );
        }

        // The platform's own environment, for an operator at the root.
        if ($this->planes->onAccountPlane() && $this->scope->isPlatformOperator()) {
            $projects[] = $this->platformProject(current: false, to: $this->landingPath($activeRoute, onEnvironmentPlane: false));
        }

        return new ShellContextProps(
            noun: $workspace ? 'Workspace' : 'Organization',
            workspaces: $this->workspaceOptions($memberships, $currentId, fallbackName: $this->user->organization()?->name),
            switchUrl: Route::has('organization.switch') ? route('organization.switch') : null,
            projects: $projects,
        );
    }

    /**
     * The environment console's context: the administrator's workspaces, the workspace this
     * environment belongs to, its projects, and the environments in them they may open.
     *
     * Every environment link goes back through the WORKSPACE host's `/open/{environment}`,
     * because that is where the person's session is — this host holds an environment
     * binding and nothing else — and that door is what checks access and mints the token.
     */
    public function forEnvironmentPlane(?Membership $membership, string $subjectId, ?string $activeRoute): ShellContextProps
    {
        // The workspace whose membership opened this console — none on the platform root's,
        // which its operators administer and no workspace owns.
        $workspaceId = $membership?->organization_id;
        $onRoot = $membership === null;
        $currentEnvironmentId = $this->environments->current()?->environmentKey();
        $to = $this->landingPath($activeRoute, onEnvironmentPlane: true);

        $memberships = $this->root->run(fn (): Collection => $this->memberships->forUser($subjectId)) ?? new Collection;

        $workspaces = $this->root->run(fn (): array => $this->workspaceOptions(
            $memberships,
            $workspaceId,
            fallbackName: null,
            openHref: $this->onWorkspaceHost('projects'),
        )) ?? [];

        $projects = $membership === null ? [] : $this->root->run(fn (): array => $this->projectsOf(
            organizationId: $membership->organization_id,
            subjectId: $subjectId,
            capabilities: OrganizationCapabilities::of($membership->role),
            currentEnvironmentId: $currentEnvironmentId,
            href: fn (string $id): string => $this->withTarget($this->onWorkspaceHost('environment.open', ['environment' => $id]), $to),
        )) ?? [];

        if ($onRoot || $this->isOperator($subjectId)) {
            $projects[] = $this->platformProject(current: $onRoot, to: $to);
        }

        if ($onRoot) {
            // The first crumb names what this console belongs to: the platform, which the
            // operator runs — with their own workspaces one click away beside it.
            array_unshift($workspaces, new SwitchOptionProps(
                id: 'platform',
                label: 'Platform admin',
                caption: 'Operator',
                current: true,
                openHref: Route::has('platform.workspaces') ? $this->onWorkspaceHost('platform.workspaces') : null,
            ));
        }

        return new ShellContextProps(
            noun: $onRoot ? 'Platform' : 'Workspace',
            workspaces: $workspaces,
            switchUrl: null,
            projects: $projects,
        );
    }

    /**
     * The PLATFORM ROOT's environment as a row of the switcher — under a "Platform" project
     * of its own, opening its console on the root's host at the same page where there is one.
     *
     * No handoff: that console is on the host the operator signed in to, and an operator
     * standing on a tenant's console is sent straight there, where their root session is.
     */
    private function platformProject(bool $current, ?string $to): ContextProjectProps
    {
        $environment = $this->root->model();
        $href = $this->onWorkspaceHost('environment.home');

        if ($to !== null && $to !== '/admin') {
            $host = $this->planes->consoleHost();
            $href = $host === null ? url($to) : 'https://'.$host.$to;
        }

        return new ContextProjectProps(
            id: 'platform',
            // The product's own name: the platform's environment is Cbox ID's sign-in.
            name: is_string($product = config('app.name')) && $product !== '' ? $product : 'Cbox ID',
            current: $current,
            environments: [new ContextEnvironmentProps(
                id: is_string($id = $environment?->getKey()) ? $id : 'platform',
                name: is_string($name = $environment?->name) && $name !== '' ? $name : 'Platform',
                type: $environment?->type->value ?? 'production',
                current: $current,
                href: $href,
            )],
        );
    }

    /** Whether a platform-root subject runs this deployment — asked once per request ({@see ConsoleScope::subjectIsOperator()}). */
    private function isOperator(string $subjectId): bool
    {
        return $this->scope->subjectIsOperator($subjectId);
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
    public function onWorkspaceHost(string $route, array $parameters = []): string
    {
        $host = $this->planes->consoleHost();

        return $host === null
            ? route($route, $parameters)
            : 'https://'.$host.route($route, $parameters, false);
    }

    /**
     * The path to land on in another environment: this page, if the environment console
     * has it without an entity in the URL; else the page of the area this request lights
     * (a user's detail page lands on Users); else nothing, which lands on the home page.
     *
     * The organization console's pages map by the console's own naming rule — `webhooks`
     * here is `environment.webhooks` there ({@see ConsoleScope::routeName()}) — so opening
     * an environment from the workspace's Webhooks page opens that environment's Webhooks.
     */
    private function landingPath(?string $activeRoute, bool $onEnvironmentPlane): ?string
    {
        $current = request()->route()?->getName();

        foreach ([$current, $activeRoute] as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $candidate = $onEnvironmentPlane ? $name : 'environment.'.$name;
            $route = Route::getRoutes()->getByName($candidate);

            if ($route === null
                || $candidate === 'environment.home'
                || $route->parameterNames() !== []
                || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // Through the same allow-list the redemption applies, so the switcher can never
            // build a target the other end would discard.
            $path = $this->targets->sanitize('/'.ltrim($route->uri(), '/'));

            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    private function withTarget(string $href, ?string $to): string
    {
        return $to === null ? $href : $href.'?'.http_build_query(['to' => $to], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The person's organizations as switcher rows — ALWAYS including the current one, so
     * the crumb can name where you are even when there is nowhere else to go.
     *
     * One membership in the overwhelming majority of sessions, and that case costs no
     * organization read at all: its name is already on the signed-in session.
     *
     * @param  Collection<int, Membership>  $memberships
     * @return list<SwitchOptionProps>
     */
    private function workspaceOptions(
        Collection $memberships,
        ?string $currentId,
        ?string $fallbackName,
        ?string $openHref = null,
    ): array {
        if ($memberships->count() <= 1 && $fallbackName !== null && $currentId !== null) {
            $membership = $memberships->first();

            return [new SwitchOptionProps(
                id: $currentId,
                label: $fallbackName,
                caption: $membership?->role->label(),
                current: true,
                openHref: $openHref,
            )];
        }

        /** @var list<string> $ids */
        $ids = [];

        foreach ($memberships as $membership) {
            $ids[] = $membership->organization_id;
        }

        $organizations = $ids === [] ? [] : $this->organizations->findMany($ids);

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
                label: $organization->name,
                caption: $membership->role->label(),
                current: $membership->organization_id === $currentId,
                openHref: $openHref,
            );
        }

        return $options;
    }

    /**
     * The workspace's projects, each with the environments this person may open.
     *
     * The two gates `/open/{environment}` applies, in the same order: the role must be one
     * that manages environments at all (a Viewer reaches environments but is never handed
     * an admin token), and each environment must be among the member's accessible ones —
     * which narrows to the grants of a member scoped to a few. A project left with nothing
     * openable is dropped rather than listed empty.
     *
     * @param  callable(string): string  $href
     * @return list<ContextProjectProps>
     */
    private function projectsOf(
        string $organizationId,
        string $subjectId,
        ?OrganizationCapabilities $capabilities,
        ?string $currentEnvironmentId,
        callable $href,
    ): array {
        if ($capabilities?->canManageEnvironments() !== true) {
            return [];
        }

        $accessible = $this->memberships->accessibleEnvironmentIds($organizationId, $subjectId);

        if ($accessible === []) {
            return [];
        }

        /** @var Collection<int, Project> $owned */
        $owned = $this->projects->forOrganization($organizationId);

        if ($owned->isEmpty()) {
            return [];
        }

        // ONE read for every environment across every project, grouped in memory — the
        // same shape as the Projects page, for the same reason.
        $environments = Environment::query()
            ->whereIn('project_id', $owned->pluck('id')->all())
            ->whereIn('id', $accessible)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'project_id'])
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
                    href: $href($environment->id),
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
