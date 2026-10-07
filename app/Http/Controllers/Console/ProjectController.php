<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Workspace\Environments\CreateEnvironment;
use App\Actions\Workspace\Projects\CreateProject;
use App\Actions\Workspace\Projects\ReactivateProject;
use App\Actions\Workspace\Projects\RenameProject;
use App\Actions\Workspace\Projects\ResendVerification;
use App\Actions\Workspace\Projects\SuspendProject;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\RenameProjectRequest;
use App\Http\Requests\Console\StoreEnvironmentRequest;
use App\Http\Requests\Console\StoreProjectRequest;
use App\Platform\Actions\ActionRefused;
use App\Platform\CurrentUser;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\Enums\ProjectStatus;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Response;

/**
 * IDENTITY PLATFORM › PROJECTS — the account's launchpad, and each product's detail.
 *
 * A project is one product's IdP: separately billed, with its own plan and environment
 * allowance, so a customer runs "Product 1" and "Product 2" from one login without a
 * second email. Its ENVIRONMENTS are what people actually come here to open, which is why
 * the index lists them under their project rather than behind a drill-down.
 *
 * ONE AUTHORIZATION RULE FOR EVERY PROJECT WRITE, and the port is where it became one.
 * The launchpad's inline "new environment" asked only for the environment-manager
 * capability while the project page asked for that AND unscoped access — so a member
 * confined to staging was refused on the page that says so and permitted on the page that
 * does not. Two spellings of one rule is not a rule; {@see self::assertMayManage()} is.
 *
 * EVERY WRITE IS AN ACTION (`app/Actions/Workspace/Projects`, `…/Environments`), the same
 * one `/api/v1/workspace` runs for a workspace key — so the page and the API refuse and
 * record alike. This controller keeps what is the console's own: which page a refusal
 * lands on, and the 403 a person who may not manage gets before anything runs.
 */
final readonly class ProjectController extends ConsoleController
{
    /**
     * The launchpad: every project the account owns, each with the environments this
     * member may reach listed under it.
     */
    public function index(
        OrganizationProjects $projects,
        Memberships $members,
        Subjects $subjects,
        CurrentUser $current,
    ): Response|RedirectResponse {
        /*
         * This page belongs to whoever's organization owns the identity providers on it.
         * Everybody else is sent somewhere real rather than refused: as a landing page a
         * 403 is a dead end with no rail and therefore no sign-out, and the operator who
         * runs the deployment buys nothing on it.
         */
        if ($this->scope->membershipRole() === null) {
            return to_route($this->scope->isPlatformOperator() ? 'platform.environments' : 'dashboard');
        }

        $organizationId = $this->scope->organizationId();
        $actorId = $this->scope->actorId();
        $allAccess = $this->hasFullAccess($members);
        $accessible = $this->reachable($members);

        $rows = [];
        $projectIds = [];

        if ($organizationId !== null) {
            $owned = $projects->forOrganization($organizationId);

            foreach ($owned as $project) {
                $projectIds[] = $project->id;
            }

            // ONE query for every environment across every project, grouped in memory.
            // This page renders the whole tree, so a per-project query is an N+1 that
            // grows with the customer.
            $environments = Environment::query()
                ->whereIn('project_id', $projectIds)
                ->orderBy('name')
                ->get()
                ->groupBy('project_id');

            foreach ($owned as $project) {
                /** @var Collection<int, Environment> $inProject */
                $inProject = $environments->get($project->id) ?? new Collection;

                $visible = $allAccess
                    ? $inProject
                    : $inProject->filter(
                        static fn (Environment $e): bool => in_array($e->id, $accessible, true),
                    );

                // A project with nothing reachable is not this member's to see.
                if ($visible->isEmpty() && ! $allAccess) {
                    continue;
                }

                $rows[] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status->value,
                    'limit' => $project->environment_limit,
                    'used' => $inProject->count(),
                    'atLimit' => $inProject->count() >= $project->environment_limit,
                    'settingsHref' => route('projects.show', $project->id),
                    'environments' => $visible->map(static fn (Environment $e): array => [
                        'id' => $e->id,
                        'name' => $e->name,
                        'type' => $e->type->value,
                        'host' => $e->domain ?? $e->slug,
                        'openHref' => route('environment.open', $e->id),
                    ])->values()->all(),
                ];
            }
        }

        return $this->page('console/projects/index', 'Projects', [
            'help' => HelpProps::for(HelpTopic::Projects),
            'projects' => $rows,
            'canManage' => $this->mayManage($members),
            'createHref' => route('projects.create'),
            // The first environment is held back until the owner confirms their address,
            // so the page has to say so — otherwise it shows a project with no
            // environments and no explanation.
            'awaitingVerification' => $this->awaitingVerification($subjects, $actorId, $projectIds),
            'verificationEmail' => $current->email() ?? '',
            // Named in the banner so "it never arrived" has somewhere to go: this is the
            // address to search the inbox for, and to allow past a spam filter.
            'verificationSender' => is_string($from = config('mail.from.address')) ? $from : '',
        ]);
    }

    public function create(): Response
    {
        // Reaching this page at all requires a membership; whether this member may stand
        // up a product is asked again at the write, against the capability rather than
        // the role.
        abort_if($this->scope->membershipRole() === null, 403);

        return $this->page('console/projects/create', 'New project', [
            'indexHref' => route('projects'),
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        abort_if($this->scope->membershipRole() === null, 403);

        // Only roles that manage environments may stand up a new product — asked here so a
        // refusal is the 403 it always was, and again by the action's gate.
        abort_if($this->scope->organizationId() === null, 403);
        abort_unless($this->scope->capabilities()?->canManageEnvironments() === true, 403);

        // The same action `POST /api/v1/workspace/projects` runs.
        $result = $this->act(CreateProject::class, ['name' => $request->name()], ['name' => 'name'], 'name');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Project $project */
        $project = $result->value;

        return to_route('projects.show', $project->id)
            ->with('status', 'Project created — add its first environment.');
    }

    /** One product's detail: its environments, its plan, and its settings. */
    public function show(string $project, Memberships $members, Projects $projects): Response
    {
        abort_if($this->scope->membershipRole() === null, 403);

        $model = $this->owned($project);
        $scoped = ! $this->hasFullAccess($members);
        $accessible = $this->reachable($members);

        /*
         * A scoped member's reachability is re-validated on THIS request rather than
         * remembered from the one that first opened the page — a grant revoked mid-session
         * otherwise keeps leaking the project's metadata to a tab left open.
         */
        if ($scoped) {
            abort_unless(
                Environment::query()
                    ->where('project_id', $model->id)
                    ->whereIn('id', $accessible)
                    ->exists(),
                403,
            );
        }

        $query = Environment::query()->where('project_id', $model->id)->orderBy('created_at');

        if ($scoped) {
            $query->whereIn('id', $accessible);
        }

        $baseDomain = $this->baseDomain();

        return $this->page('console/projects/show', $model->name, [
            'project' => [
                'id' => $model->id,
                'name' => $model->name,
                'slug' => $model->slug,
                'status' => $model->status->value,
                'suspended' => $model->status === ProjectStatus::Suspended,
                'limit' => $model->environment_limit,
            ],
            'environments' => $query->get()->map(fn (Environment $environment): array => [
                'id' => $environment->id,
                'name' => $environment->name,
                'sandbox' => $environment->isSandbox(),
                'status' => $environment->status->value,
                // The environment's own front door, which is a real URL somebody pastes
                // to a colleague — not a route on this console.
                'url' => 'https://'.($environment->domain ?? $environment->slug.'.'.$baseDomain),
                'openHref' => route('environment.open', $environment->id),
            ])->values()->all(),
            // A management surface requires the capability AND full access.
            'canManage' => ! $scoped && $this->mayManage($members),
            'scoped' => $scoped,
            'remaining' => $projects->remainingEnvironments($model),
            'indexHref' => route('projects'),
        ]);
    }

    public function rename(RenameProjectRequest $request, string $project, Memberships $members): RedirectResponse
    {
        $this->assertMayManage($members);

        $result = $this->act(RenameProject::class, ['id' => $this->owned($project)->id, 'name' => $request->name()], ['name' => 'name'], 'name');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Project renamed.');
    }

    /**
     * Add an environment to a project — from the project page, or inline from the
     * launchpad. One endpoint, because it was one act with two authorization rules.
     */
    public function storeEnvironment(
        StoreEnvironmentRequest $request,
        string $project,
        Memberships $members,
    ): RedirectResponse {
        $this->assertMayManage($members);

        // Resolved against the acting organization, so a posted id belonging to another
        // account is a 404 rather than a permitted write. The action — the one
        // `POST /api/v1/workspace/environments` runs — provisions it and records it.
        $result = $this->act(
            CreateEnvironment::class,
            ['name' => $request->name(), 'type' => $request->type()->value, 'project_id' => $this->owned($project)->id],
            ['name' => 'name', 'type' => 'type'],
            'name',
            ['environment_limit_reached' => 'This project is at its environment limit. Upgrade its plan to add more.'],
        );

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Environment created.');
    }

    public function suspend(string $project, Memberships $members): RedirectResponse
    {
        $this->assertMayManage($members);

        $result = $this->act(SuspendProject::class, ['id' => $this->owned($project)->id]);

        return $result instanceof RedirectResponse ? $result : back()->with(
            'status',
            'Project suspended — its environments stay live but no new ones can be added until reactivated.',
        );
    }

    public function reactivate(string $project, Memberships $members): RedirectResponse
    {
        $this->assertMayManage($members);

        $result = $this->act(ReactivateProject::class, ['id' => $this->owned($project)->id]);

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Project reactivated.');
    }

    /**
     * Re-send the signup confirmation — the only way back for an owner whose email is
     * lost, filtered or expired, now that their first environment waits on it.
     *
     * NO ADDRESS IS ACCEPTED HERE. It mails whatever is on the row the session resolves;
     * there is deliberately no parameter anybody could point somewhere else.
     */
    public function resendVerification(CurrentUser $current): RedirectResponse
    {
        abort_if($current->subject() === null, 403);

        /*
         * Outbound mail is the scarce, abusable resource: anyone who got this far could
         * otherwise pump mail at their own inbox and burn the sending reputation for
         * everyone. The action limits it per PERSON mailed, whichever door asks
         * ({@see ResendVerification}).
         *
         * ON THE INERTIA FLASH CHANNEL, not the session's `status` — the rate-limit
         * refusal too. The answer to this click belongs to THIS click and nothing else:
         * the toaster shows `status` for every mutation on the console, and a rate-limit
         * sentence announced as a success toast is the wrong shape entirely. It is
         * rendered on the page, beside the button that asked.
         */
        try {
            $result = $this->runAction(ResendVerification::class, []);
        } catch (ActionRefused $refused) {
            $this->inertia->flash('resendNotice', $refused->getMessage());

            return back();
        }

        $message = $result->payload['message'] ?? null;

        $this->inertia->flash('resendNotice', is_string($message) ? $message : '');

        return back();
    }

    /**
     * The named project IF the acting organization owns it, or 404.
     *
     * The organization id is in the QUERY rather than compared afterwards — the same rule
     * the member roster follows, and for the same reason: a comparison after the fact is
     * one refactor away from being dropped.
     */
    private function owned(string $projectId): Project
    {
        $organizationId = $this->scope->organizationId();

        $model = $organizationId === null
            ? null
            : Project::query()->whereKey($projectId)->where('organization_id', $organizationId)->first();

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * THE rule for every project write: the environment-manager capability, and access
     * that is not confined to a subset of the project's environments.
     */
    private function assertMayManage(Memberships $members): void
    {
        abort_if($this->scope->membershipRole() === null, 403);
        abort_unless($this->mayManage($members) && $this->hasFullAccess($members), 403);
    }

    private function mayManage(Memberships $members): bool
    {
        return $this->scope->capabilities()?->canManageEnvironments() === true
            && $this->hasFullAccess($members);
    }

    /** Whether the acting member reaches every environment, rather than a granted subset. */
    private function hasFullAccess(Memberships $members): bool
    {
        $organizationId = $this->scope->organizationId();
        $actorId = $this->scope->actorId();

        return $organizationId !== null
            && $actorId !== ''
            && app(PlatformRoot::class)->run(
                fn () => $members->of($organizationId, $actorId),
            )?->all_environments === true;
    }

    /**
     * The environments this member may reach.
     *
     * IN THE PLATFORM ROOT. `memberships` is environment-owned and the console is served
     * on whichever host the deployment puts it on — asked directly, this answers "no
     * environments" for somebody who reaches several, and the page silently shows nothing.
     *
     * @return list<string>
     */
    private function reachable(Memberships $members): array
    {
        $organizationId = $this->scope->organizationId();
        $actorId = $this->scope->actorId();

        return $organizationId === null || $actorId === ''
            ? []
            : app(PlatformRoot::class)->run(
                fn (): array => $members->accessibleEnvironmentIds($organizationId, $actorId),
            ) ?? [];
    }

    /**
     * True while the account's first environment is still held back pending the owner's
     * email confirmation.
     *
     * @param  list<string>  $projectIds  the account's products, already loaded by the
     *                                    caller so this costs no second read of them
     */
    private function awaitingVerification(Subjects $subjects, string $subjectId, array $projectIds): bool
    {
        if ($subjectId === '' || $projectIds === []) {
            return false;
        }

        if (Environment::query()->whereIn('project_id', $projectIds)->exists()) {
            return false;
        }

        // A customer's people are subjects in the PLATFORM ROOT, so the lookup runs in
        // that scope — the tenancy kernel is deny-by-default and would otherwise see
        // nothing, and answer "unverified" for somebody who is.
        $verified = app(PlatformRoot::class)->run(
            fn (): bool => $subjects->find($subjectId)?->emailVerified === true,
        );

        return $verified === false;
    }

    /** The host an environment's slug hangs off, for the URL shown beside it. */
    private function baseDomain(): string
    {
        $bases = config('cbox-id.environments.base_domains', []);
        $first = is_array($bases) && isset($bases[0]) && is_string($bases[0]) ? $bases[0] : null;

        return $first ?? request()->getHost();
    }
}
