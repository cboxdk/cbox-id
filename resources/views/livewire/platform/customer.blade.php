<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\OperatorEnvironment;
use Carbon\CarbonInterface;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Cbox\Id\Platform\PlatformRoot;
use Livewire\Volt\Component;

/**
 * The spine of the operator plane: one CUSTOMER, and everything that hangs off it.
 *
 * The accounts list told an operator that Acme has three projects and three
 * environments — and contained no `route()` call at all. Those numbers were the end of
 * the road: you learned a count and had nowhere to click, so the only way to find out
 * WHICH three environments was to open the flat environments list and know Acme's plane
 * names by heart. This page is where account → project → environment is actually
 * walkable, and it is the reason those counts are now links.
 *
 * Everything above the tenancy boundary is read globally (accounts, projects,
 * environments and the customer's members are not environment-owned). The two per-environment
 * tallies are NOT — organizations and users live inside a plane — so they are counted
 * once for the whole page inside {@see EnvironmentContext::withoutScope()}, grouped, in
 * the two queries the accounts list already uses for its own counts. A page whose job
 * is to show every environment an account owns must not pay a query per environment.
 *
 * What can be CHANGED here is deliberately narrow. Suspension of the customer is the same
 * reversible off-switch the list carries, through the same {@see Accounts} contract, so
 * it is attributed to the acting operator and recorded by the contract rather than at
 * this call site. Targeting an environment is a console preference, not a change to
 * anything. Nothing here deletes, purges, or reassigns — the platform root and the
 * unattached environment in particular are surfaced and explained, never adopted.
 */
new #[Layout('components.layouts.platform', ['title' => 'Customer', 'width' => '72rem'])] class extends Component
{
    public string $organizationId = '';

    /**
     * Re-check operator AUTHORITY on every request, including Livewire actions.
     *
     * boot(), not mount(): Livewire re-runs mount only on the initial GET, so a check
     * that lived there would leave `toggleStatus` and the targeting actions reachable by
     * a crafted update request from a session that is no longer an operator's.
     */
    public function boot(ConsoleScope $scope): void
    {
        abort_unless($scope->isPlatformOperator(), 404);
    }

    public function mount(string $organization, Organizations $organizations, PlatformRoot $platformRoot): void
    {
        // IN THE PLATFORM ROOT: `organizations` is environment-owned, and an operator
        // reaches this page from whichever plane the console happens to be pointed at.
        $model = $platformRoot->run(fn () => $organizations->find($organization));

        abort_if($model === null, 404);

        $this->organizationId = $model->id;
    }

    /**
     * Suspend or reactivate — the same contract call, copy and blast radius as the list.
     * The audit entry is written by {@see Organizations::suspend()} itself, not here.
     */
    public function toggleStatus(Organizations $organizations, ConsoleScope $scope, PlatformRoot $platformRoot): void
    {
        $actorId = $scope->operator()?->id;

        if ($actorId === null) {
            abort(403);
        }

        $suspending = $platformRoot->run(function () use ($organizations, $actorId): ?bool {
            $organization = $organizations->find($this->organizationId);

            if ($organization === null) {
                return null;
            }

            $suspending = ! $organization->status->revokesAccess();

            $suspending
                ? $organizations->suspend($organization->id, $actorId)
                : $organizations->reactivate($organization->id, $actorId);

            return $suspending;
        });

        if ($suspending === null) {
            return;
        }

        $this->dispatch('toast', message: $suspending
            ? 'Customer suspended — its members can no longer sign in and its environments stop serving auth.'
            : 'Customer reactivated.');
    }

    /**
     * Point the console at one of THIS customer's environments and stay here, so the
     * operator can see which plane they are now reading from without losing the customer
     * they were looking at.
     */
    public function target(string $id, OperatorEnvironment $environments): void
    {
        $environment = $this->ownedEnvironment($id);

        $environments->pointAt($environment->slug);

        // navigate: false — repointing changes what every subsequent read in the console
        // resolves to, and a wire:navigate hand-off would restore a cached document
        // rendered against the previous plane.
        $this->redirect(route('platform.customers.show', $this->organizationId), navigate: false);
    }

    /**
     * Target an environment AND open it — the tenants inside that plane. Two steps that
     * were only ever available as two pages: the flat environments list to switch, then
     * the Organizations entry in the rail to look.
     */
    public function open(string $id, OperatorEnvironment $environments): void
    {
        $environment = $this->ownedEnvironment($id);

        $environments->pointAt($environment->slug);

        $this->redirect(route('platform.organizations'), navigate: false);
    }

    /**
     * The environment named by an action, refused unless THIS customer owns it.
     *
     * Deny-by-default rather than "look it up and use it": both actions above take an id
     * straight off the wire, and without the ownership predicate either one would let a
     * request made from this page repoint the console at any environment on the install —
     * including the platform root. An operator may legitimately do that from the
     * environments list, which is a different page with a different confirmation; it is
     * not what this page offers, so it is not what this page accepts.
     */
    private function ownedEnvironment(string $id): Environment
    {
        // OWNERSHIP RUNS THROUGH THE PROJECT — `environments.account_id` is gone, and the
        // predicate stays IN the query rather than becoming a comparison afterwards. This
        // is the one place on the page that takes an id straight off the wire.
        $environment = Environment::query()
            ->whereKey($id)
            ->whereIn('project_id', Project::query()->where('organization_id', $this->organizationId)->pluck('id'))
            ->first();

        abort_if($environment === null, 404);

        return $environment;
    }

    /** @return array<string, mixed> */
    public function with(
        Organizations $organizations,
        Memberships $members,
        OrganizationProjects $projects,
        Projects $projectPlans,
        EnvironmentContext $context,
        PlatformRoot $platformRoot,
    ): array {
        $account = $platformRoot->run(fn () => $organizations->find($this->organizationId));
        abort_if($account === null, 404);

        $projectList = $projects->forOrganization($account->id);
        $projectIds = $projectList->pluck('id')->all();

        // Every environment the customer owns, in ONE read, THROUGH the projects —
        // `environments.account_id` was the shortcut and it is gone. Grouped by project
        // below rather than queried per project: a five-product customer would otherwise
        // cost five reads to render the same rows.
        $environments = Environment::query()
            ->whereIn('project_id', $projectIds)
            ->orderBy('created_at')
            ->get();

        /** @var array<string, list<Environment>> $byProject */
        $byProject = [];
        /** @var list<Environment> $unfiled */
        $unfiled = [];

        foreach ($environments as $environment) {
            $projectId = $environment->getAttribute('project_id');

            if (is_string($projectId) && $projectId !== '') {
                $byProject[$projectId][] = $environment;
            } else {
                // An environment the customer owns that sits in no project. The same
                // shape as the install-wide orphan, one level down, and shown for the
                // same reason: a row that exists and is not rendered anywhere is a row
                // nobody will ever go and look at.
                $unfiled[] = $environment;
            }
        }

        $environmentIds = $environments->modelKeys();

        // Organizations and users live INSIDE a plane, so these two are the only reads
        // on the page that need the scope escape — and they are grouped, so the page
        // costs the same two queries whether the customer owns one environment or twenty.
        [$orgCounts, $userCounts] = $context->withoutScope(function () use ($environmentIds): array {
            if ($environmentIds === []) {
                return [[], []];
            }

            /** @var Collection<string, int> $orgs */
            $orgs = Organization::query()->selectRaw('environment_id, count(*) as c')
                ->whereIn('environment_id', $environmentIds)
                ->groupBy('environment_id')->pluck('c', 'environment_id');

            /** @var Collection<string, int> $users */
            $users = User::query()->selectRaw('environment_id, count(*) as c')
                ->whereIn('environment_id', $environmentIds)
                ->groupBy('environment_id')->pluck('c', 'environment_id');

            return [$orgs->all(), $users->all()];
        });

        /** @var array<string, int> $remaining */
        $remaining = [];

        foreach ($projectList as $project) {
            $remaining[$project->id] = $projectPlans->remainingEnvironments($project);
        }

        // The package's model does not declare the Eloquent timestamp columns as
        // @property, so read created_at off the attribute bag and narrow it rather than
        // trusting an undeclared property.
        $createdAt = $account->getAttribute('created_at');

        [$roster, $people] = $platformRoot->run(function () use ($members, $account): array {
            $roster = $members->forOrganization($account->id);
            $people = [];

            foreach ($roster as $membership) {
                $people[$membership->user_id] = app(Subjects::class)->find($membership->user_id);
            }

            return [$roster, $people];
        }) ?? [collect(), []];

        return [
            'account' => $account,
            // Asked ONCE, in the component: an organization has no isActive() — the access
            // decision lives on the enum, so the template must not re-derive it.
            'accountIsActive' => ! $account->status->revokesAccess(),
            'createdAt' => $createdAt instanceof CarbonInterface ? $createdAt->toDayDateTimeString() : null,
            // Memberships AND the people behind them. A membership carries authority, not
            // identity, so an operator looking at a customer's roster needs both — and the
            // subjects are hydrated in one pass rather than per row.
            'members' => $roster,
            'people' => $people,
            'projects' => $projectList,
            'environmentsByProject' => $byProject,
            'unfiledEnvironments' => $unfiled,
            'environmentTotal' => $environments->count(),
            'orgCounts' => $orgCounts,
            'userCounts' => $userCounts,
            'remaining' => $remaining,
            'activeEnvironmentId' => $context->current()?->environmentKey(),
        ];
    }
}; ?>

@php
    /** @var \Cbox\Id\Organization\Models\Organization $account */
    /** @var \Illuminate\Support\Collection<int, \Cbox\Id\Organization\Models\Membership> $members */
    /** @var \Illuminate\Support\Collection<int, \Cbox\Id\Platform\Models\Project> $projects */
    $suspendConfirm = 'Suspend '.$account->name.'? Its '.$members->count().' member(s) are signed out and all '
        .$environmentTotal.' environment(s) it owns stop serving auth on the next request. You can reactivate it here.';
@endphp

<div>
    <div class="mb-5">
        <a href="{{ route('platform.customers') }}" wire:navigate
           class="inline-flex items-center gap-1 text-sm" style="color:var(--muted)">
            <span aria-hidden="true">&larr;</span> Back to customers
        </a>
    </div>

    {{-- No hand-written eyebrow: the route is named `platform.customers.show`, so the nav
         registry already owns it under Platform › Accounts and x-page-header reads the
         label from there. --}}
    <x-page-header :title="$account->name"
                   subtitle="One customer on this install — its team, its projects, and every environment those projects own. Suspending it signs out its members and stops all of them serving auth.">
        <x-slot:actions>
            <button wire:click="toggleStatus" class="btn {{ $accountIsActive ? 'btn-ghost' : 'btn-primary' }}"
                    wire:loading.attr="disabled" wire:target="toggleStatus"
                    wire:confirm="{{ $accountIsActive ? $suspendConfirm : 'Reactivate '.$account->name.'? Its members can sign in again and its environments resume serving auth.' }}">
                <span class="spinner" wire:loading wire:target="toggleStatus" aria-hidden="true"></span>
                {{ $accountIsActive ? 'Suspend' : 'Reactivate' }}
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- Overview --}}
    <div class="cbx-panel mb-5 mt-8">
        <div class="cbx-panel-body">
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <h2 class="text-base font-semibold">{{ $account->name }}</h2>
                @if ($accountIsActive)
                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Active</span>
                @else
                    <span class="cbx-pill cbx-pill--destructive"><span class="dot"></span>Suspended</span>
                @endif
            </div>

            <dl>
                <div class="cbx-kv"><dt>Organization ID</dt><dd class="font-mono text-xs">{{ $account->id }}</dd></div>
                <div class="cbx-kv"><dt>Members</dt><dd>{{ $members->count() }}</dd></div>
                <div class="cbx-kv"><dt>Projects</dt><dd>{{ $projects->count() }}</dd></div>
                <div class="cbx-kv"><dt>Environments</dt><dd>{{ $environmentTotal }}</dd></div>
                @if ($createdAt !== null)
                    <div class="cbx-kv"><dt>Created</dt><dd>{{ $createdAt }}</dd></div>
                @endif
            </dl>
        </div>
    </div>

    {{-- Team --}}
    <div class="cbx-panel overflow-hidden mb-5">
        <div class="cbx-panel-header">
            <h2 class="cbx-panel-title">Team</h2>
            <span class="text-xs" style="color:var(--faint)">{{ $members->count() }} {{ \Illuminate\Support\Str::plural('member', $members->count()) }}</span>
        </div>
        @if ($members->isEmpty())
            <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">
                Nobody is on this customer — it has no owner, so nobody can sign in to administer it.
                That is a provisioning failure worth chasing, not an empty list.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Member</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Environments</th>
                            <th scope="col">Last sign-in</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($members as $member)
                            <tr wire:key="member-{{ $member->id }}">
                                <td>
                                    <p class="font-medium">{{ ($people[$member->user_id] ?? null)?->email ?? '—' }}</p>
                                    @if ($member->name !== null && $member->name !== '')
                                        <p class="text-xs" style="color:var(--faint)">{{ $member->name }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">{{ $member->role->label() }}</td>
                                <td class="whitespace-nowrap">
                                    <span class="cbx-pill {{ $member->status === MembershipStatus::Active ? 'cbx-pill--success' : 'cbx-pill--warning' }}">
                                        <span class="dot"></span><span class="capitalize">{{ $member->status->value }}</span>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap text-xs" style="color:var(--muted)">
                                    {{ $member?->all_environments === true ? 'All' : 'Selected only' }}
                                </td>
                                <td class="whitespace-nowrap text-xs" style="color:var(--faint)">
                                    {{ $member->last_login_at?->toDayDateTimeString() ?? 'Never' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Projects → environments. The walk the counts on the accounts list used to
         promise and could not deliver. --}}
    <section>
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
            <h2 class="text-sm font-semibold">Projects &amp; environments</h2>
            <p role="status" aria-live="polite" class="text-xs" style="color:var(--faint)">
                {{ $projects->count() }} {{ \Illuminate\Support\Str::plural('project', $projects->count()) }},
                {{ $environmentTotal }} {{ \Illuminate\Support\Str::plural('environment', $environmentTotal) }}
            </p>
        </div>

        @if ($projects->isEmpty() && $unfiledEnvironments === [])
            <div class="cbx-empty">
                <div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div>
                <h3>No projects yet</h3>
                <p>
                    A project is one IdP product this customer owns, and it is what environments hang off —
                    so until the customer creates one from its own workspace, there is nothing here to target or open.
                    Nothing on this screen creates one on the customer's behalf.
                </p>
            </div>
        @endif

        @foreach ($projects as $project)
            @php $projectEnvironments = $environmentsByProject[$project->id] ?? []; @endphp
            <div class="cbx-panel overflow-hidden mb-5" wire:key="project-{{ $project->id }}">
                <div class="cbx-panel-header">
                    <span class="flex flex-wrap items-center gap-2 min-w-0">
                        <h3 class="cbx-panel-title">{{ $project->name }}</h3>
                        @if ($project->isActive())
                            <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Active</span>
                        @else
                            <span class="cbx-pill cbx-pill--destructive"><span class="dot"></span>Suspended</span>
                        @endif
                    </span>
                    <span class="text-xs" style="color:var(--faint)">
                        {{ count($projectEnvironments) }} of {{ $project->environment_limit }} environments
                        · {{ $remaining[$project->id] ?? 0 }} left on plan
                    </span>
                </div>

                @if ($projectEnvironments === [])
                    <div class="px-5 py-8 text-center text-sm" style="color:var(--faint)">
                        This project has no environments yet — nothing under it serves auth.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th scope="col">Environment</th>
                                    <th scope="col">Domain</th>
                                    <th scope="col" class="text-right">Orgs</th>
                                    <th scope="col" class="text-right">Users</th>
                                    <th scope="col"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($projectEnvironments as $environment)
                                    <tr wire:key="environment-{{ $environment->id }}">
                                        <td>
                                            <div class="flex items-center gap-3">
                                                <span aria-hidden="true" class="grid place-items-center rounded-md text-xs font-bold shrink-0"
                                                      style="width:1.9rem;height:1.9rem;background:var(--accent-soft);color:var(--accent-strong)">
                                                    {{ strtoupper(substr($environment->name, 0, 1)) }}
                                                </span>
                                                <div class="min-w-0">
                                                    <p class="font-semibold">
                                                        {{ $environment->name }}
                                                        @if ($environment->id === $activeEnvironmentId)
                                                            <span class="cbx-pill cbx-pill--success align-middle ml-1"><span class="dot"></span>Target</span>
                                                        @endif
                                                        @if ($environment->isSandbox())
                                                            <span class="cbx-pill align-middle ml-1">Sandbox</span>
                                                        @endif
                                                        @unless ($environment->status->canServe())
                                                            <span class="cbx-pill cbx-pill--destructive align-middle ml-1"><span class="dot"></span>Suspended</span>
                                                        @endunless
                                                    </p>
                                                    <p class="text-xs font-mono" style="color:var(--faint)">{{ $environment->slug }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="color:var(--muted)">{{ $environment->domain ?? 'None — served on the fallback host' }}</td>
                                        <td class="text-right tabular-nums">{{ $orgCounts[$environment->id] ?? 0 }}</td>
                                        <td class="text-right tabular-nums">{{ $userCounts[$environment->id] ?? 0 }}</td>
                                        <td class="text-right whitespace-nowrap">
                                            {{-- Opening a plane repoints EVERY subsequent read in the console at
                                                 it, so both controls confirm and both show a busy state: with
                                                 neither, a slow switch looked like a dead button and the next
                                                 page was quietly about a different estate. --}}
                                            <button wire:click="open('{{ $environment->id }}')" class="btn btn-ghost btn-sm"
                                                    wire:loading.attr="disabled" wire:target="open('{{ $environment->id }}')"
                                                    wire:confirm="Open {{ $environment->name }}? The console is pointed at this plane and every page you open from now on reads it instead of the current one. Nothing is changed in either.">
                                                <span class="spinner" wire:loading wire:target="open('{{ $environment->id }}')" aria-hidden="true"></span>
                                                Open tenants
                                            </button>
                                            @if ($environment->id === $activeEnvironmentId)
                                                <span class="text-xs" style="color:var(--faint)">Target</span>
                                            @else
                                                <button wire:click="target('{{ $environment->id }}')" class="btn btn-ghost btn-sm"
                                                        wire:loading.attr="disabled" wire:target="target('{{ $environment->id }}')"
                                                        wire:confirm="Point this console at {{ $environment->name }}? Every page you open from now on — organizations, usage, tenant detail — reads that plane instead of the current one. Nothing is changed in either.">
                                                    <span class="spinner" wire:loading wire:target="target('{{ $environment->id }}')" aria-hidden="true"></span>
                                                    Target
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach

        @if ($unfiledEnvironments !== [])
            <div class="cbx-panel overflow-hidden mb-5">
                <div class="cbx-panel-header">
                    <h3 class="cbx-panel-title">Not in a project</h3>
                    <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>Unfiled</span>
                </div>
                <div class="px-5 pt-4 text-sm" style="color:var(--muted)">
                    This customer owns {{ count($unfiledEnvironments) }} {{ \Illuminate\Support\Str::plural('environment', count($unfiledEnvironments)) }}
                    that no project holds, so nothing bills for {{ count($unfiledEnvironments) === 1 ? 'it' : 'them' }} and
                    {{ count($unfiledEnvironments) === 1 ? 'it does' : 'they do' }} not appear on the customer's own project pages.
                    Shown here rather than left invisible; nothing on this screen reassigns {{ count($unfiledEnvironments) === 1 ? 'it' : 'them' }}.
                </div>
                <div class="overflow-x-auto mt-4">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">Environment</th>
                                <th scope="col">Domain</th>
                                <th scope="col" class="text-right">Orgs</th>
                                <th scope="col" class="text-right">Users</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($unfiledEnvironments as $environment)
                                <tr wire:key="unfiled-{{ $environment->id }}">
                                    <td>
                                        <p class="font-semibold">
                                            {{ $environment->name }}
                                            @if ($environment->id === $activeEnvironmentId)
                                                <span class="cbx-pill cbx-pill--success align-middle ml-1"><span class="dot"></span>Target</span>
                                            @endif
                                        </p>
                                        <p class="text-xs font-mono" style="color:var(--faint)">{{ $environment->slug }}</p>
                                    </td>
                                    <td style="color:var(--muted)">{{ $environment->domain ?? 'None — served on the fallback host' }}</td>
                                    <td class="text-right tabular-nums">{{ $orgCounts[$environment->id] ?? 0 }}</td>
                                    <td class="text-right tabular-nums">{{ $userCounts[$environment->id] ?? 0 }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        <button wire:click="open('{{ $environment->id }}')" class="btn btn-ghost btn-sm"
                                                wire:loading.attr="disabled" wire:target="open('{{ $environment->id }}')"
                                                wire:confirm="Open {{ $environment->name }}? The console is pointed at this plane and every page you open from now on reads it instead of the current one. Nothing is changed in either.">
                                            <span class="spinner" wire:loading wire:target="open('{{ $environment->id }}')" aria-hidden="true"></span>
                                            Open tenants
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>

    <p class="mt-4 text-xs" style="color:var(--faint)">
        Suspension is the only thing this page changes, and it is reversible. Targeting and
        opening an environment move the console's own view; they change nothing in either plane.
    </p>
</div>
