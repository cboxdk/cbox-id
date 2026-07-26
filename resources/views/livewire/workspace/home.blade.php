<?php

declare(strict_types=1);

use App\Platform\AccountActivity;
use App\Platform\AccountAuth;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\Exceptions\EnvironmentLimitReached;
use Cbox\Id\Platform\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › Projects — the account's launchpad.
 *
 * Everything the account owns is on ONE page: each project with its environments
 * listed under it. Opening an environment's console is a single click from here, and
 * so is adding one. The previous design showed project cards only, so reaching the
 * thing people actually come for — an environment — meant drilling into a project
 * page first.
 *
 * The project row carries only what belongs to the project (settings, the plan's seat
 * count); environment work happens on the environment rows.
 */
new #[Layout('components.layouts.workspace', ['title' => 'Projects'])] class extends Component
{
    /** The project whose inline "new environment" form is open, if any. */
    public ?string $creatingIn = null;

    public string $newEnvironment = '';

    public string $newEnvironmentType = 'production';

    public function startCreate(string $projectId): void
    {
        $this->creatingIn = $projectId;
        $this->newEnvironment = '';
        $this->newEnvironmentType = 'production';
        $this->resetErrorBag();
    }

    public function cancelCreate(): void
    {
        $this->reset('creatingIn', 'newEnvironment', 'newEnvironmentType');
    }

    /**
     * Add an environment to a project without leaving this page.
     *
     * The project is re-resolved against the member's account and the manage capability
     * re-checked here: the inline form is a convenience, never a second authorization
     * path — a posted project id is honoured only if it genuinely belongs to this
     * account and this member may manage it.
     */
    public function addEnvironment(AccountAuth $auth, AccountProvisioner $provisioner, AccountActivity $activity): void
    {
        $member = $auth->current();
        abort_if($member === null || ! $member->role->canManageEnvironments(), 403);

        $project = Project::query()->whereKey($this->creatingIn)->first();
        abort_if($project === null || $project->account_id !== $member->account_id, 404);

        $this->validate([
            'newEnvironment' => 'required|string|max:120',
            'newEnvironmentType' => ['required', Rule::enum(EnvironmentType::class)],
        ], attributes: ['newEnvironment' => 'environment name']);

        try {
            $environment = $provisioner->addEnvironment(
                $project,
                trim($this->newEnvironment),
                type: EnvironmentType::from($this->newEnvironmentType),
            );
        } catch (EnvironmentLimitReached) {
            $this->addError('newEnvironment', 'This project is at its environment limit. Upgrade its plan to add more.');

            return;
        }

        $activity->record($member->account_id, 'account.environment_created', $member->id,
            targetType: 'environment', targetId: $environment->id,
            context: ['name' => trim($this->newEnvironment), 'type' => $this->newEnvironmentType], request: request());

        $this->cancelCreate();
        $this->dispatch('toast', message: 'Environment created.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth, Projects $projects, AccountMembers $members): array
    {
        $member = $auth->current();
        $account = $member?->account;
        $allAccess = $member->all_environments ?? false;

        // The environments this member may reach — an all-access member sees every one
        // the account owns; a scoped member only their grants.
        $accessibleIds = $member === null ? [] : $members->accessibleEnvironmentIds($member);

        $rows = [];

        if ($account !== null) {
            $projectList = $projects->forAccount($account->id);
            $projectIds = [];
            foreach ($projectList as $project) {
                $projectIds[] = $project->id;
            }

            // One query for every environment across every project, grouped in memory.
            // This page renders the whole tree, so a per-project query would be an N+1
            // that grows with the account.
            $environments = Environment::query()
                ->whereIn('project_id', $projectIds)
                ->orderBy('name')
                ->get()
                ->groupBy('project_id');

            foreach ($projectList as $project) {
                /** @var Collection<int, Environment> $owned */
                $owned = $environments->get($project->id) ?? new Collection;
                $visible = $allAccess
                    ? $owned
                    : $owned->filter(static fn (Environment $e): bool => in_array($e->id, $accessibleIds, true));

                // A project with nothing reachable is not this member's to see.
                if ($visible->isEmpty() && ! $allAccess) {
                    continue;
                }

                $rows[] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status,
                    'limit' => $project->environment_limit,
                    'used' => $owned->count(),
                    'atLimit' => $owned->count() >= $project->environment_limit,
                    'environments' => $visible->map(static fn (Environment $e): array => [
                        'id' => $e->id,
                        'name' => $e->name,
                        'type' => $e->type,
                        'slug' => $e->slug,
                        'domain' => $e->domain,
                    ])->values()->all(),
                ];
            }
        }

        return [
            'projects' => $rows,
            'canManage' => $member?->role->canManageEnvironments() ?? false,
        ];
    }
}; ?>

<div>
    <x-page-header title="Projects" subtitle="Each project is a separate IdP product — its own environments, sign-in, and plan.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('workspace.projects.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New project</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="mt-6 space-y-4">
        @forelse ($projects as $project)
            <section class="rounded-xl border" style="border-color:var(--border)" wire:key="project-{{ $project['id'] }}">

                {{-- Project header: the project's own identity and controls only. --}}
                <header class="flex items-center gap-3 px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="font-medium truncate">{{ $project['name'] }}</h2>
                            @if ($project['status']->value !== 'active')
                                <span class="badge badge-warn">{{ $project['status']->value }}</span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs" style="color:var(--faint)">
                            <span class="tabular-nums">{{ $project['used'] }} of {{ $project['limit'] }}</span>
                            {{ \Illuminate\Support\Str::plural('environment', $project['limit']) }}
                        </p>
                    </div>

                    @if ($canManage)
                        <a href="{{ route('workspace.projects.show', $project['id']) }}"
                           class="btn btn-ghost btn-sm shrink-0"
                           aria-label="Project settings for {{ $project['name'] }}"
                           title="Project settings">
                            <x-icon name="settings" class="w-4 h-4" />
                        </a>
                    @endif
                </header>

                {{-- Environments — what people actually come here to open. --}}
                <ul class="border-t" style="border-color:var(--border)">
                    @forelse ($project['environments'] as $env)
                        <li class="flex items-center gap-3 px-5 py-3" wire:key="env-{{ $env['id'] }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm truncate">{{ $env['name'] }}</span>
                                    <span class="badge">{{ $env['type']->value }}</span>
                                </div>
                                <p class="mt-0.5 text-xs mono truncate" style="color:var(--faint)">{{ $env['domain'] ?? $env['slug'] }}</p>
                            </div>

                            <a href="{{ route('workspace.environment.open', $env['id']) }}"
                               class="btn btn-secondary btn-sm shrink-0">Open</a>
                        </li>
                    @empty
                        <li class="px-5 py-3 text-sm" style="color:var(--faint)">No environments you can reach in this project.</li>
                    @endforelse
                </ul>

                {{-- Add an environment without leaving the page. --}}
                @if ($canManage)
                    <div class="border-t px-5 py-3" style="border-color:var(--border)">
                        @if ($creatingIn === $project['id'])
                            <form wire:submit="addEnvironment" class="flex flex-wrap items-start gap-2">
                                <div class="min-w-[12rem] flex-1">
                                    <input wire:model="newEnvironment" type="text" class="input" placeholder="Staging"
                                           aria-label="Environment name"
                                           @error('newEnvironment') aria-invalid="true" aria-describedby="new-env-error" @enderror>
                                    @error('newEnvironment') <p id="new-env-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                                </div>
                                <select wire:model="newEnvironmentType" class="input w-auto shrink-0" aria-label="Environment type">
                                    <option value="production">Production</option>
                                    <option value="sandbox">Sandbox</option>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm shrink-0" wire:loading.attr="disabled" wire:target="addEnvironment">Create</button>
                                <button type="button" class="btn btn-ghost btn-sm shrink-0" wire:click="cancelCreate">Cancel</button>
                            </form>
                        @elseif ($project['atLimit'])
                            <p class="text-xs" style="color:var(--faint)">This project is at its environment limit. Upgrade its plan to add more.</p>
                        @else
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="startCreate('{{ $project['id'] }}')">
                                <x-icon name="plus" class="w-4 h-4" /> New environment
                            </button>
                        @endif
                    </div>
                @endif
            </section>
        @empty
            <div class="rounded-xl border p-8 text-center" style="border-color:var(--border)">
                <p class="font-medium">No projects yet</p>
                <p class="mx-auto mt-1 max-w-md text-sm" style="color:var(--muted)">A <strong>project</strong> is one product's IdP. It holds isolated <strong>environments</strong> — production and sandbox — each with its own users, keys and sign-in, and is billed on its own plan.</p>
                @if ($canManage)
                    <a href="{{ route('workspace.projects.create') }}" class="btn btn-primary btn-sm mt-4"><x-icon name="plus" class="w-4 h-4" /> Create your first project</a>
                @endif
            </div>
        @endforelse
    </div>
</div>
