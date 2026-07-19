<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\Projects;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › Projects — the account's launchpad. Each project is one IdP product
 * (Clerk's "Application") with its own environments and plan; a member can own
 * several under one login, billed separately. Opening a project drills into its
 * environments. Stateless: no "current" project/environment is pinned.
 */
new #[Layout('components.layouts.workspace', ['title' => 'Projects'])] class extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth, Projects $projects, AccountMembers $members): array
    {
        $member = $auth->current();
        $account = $member?->account;

        // The environments this member may reach — an all-access member sees every
        // one the account owns; a scoped member only their grants. A project is
        // visible when it holds at least one reachable environment.
        $accessibleIds = $member === null ? [] : $members->accessibleEnvironmentIds($member);

        $cards = [];
        if ($account !== null) {
            foreach ($projects->forAccount($account->id) as $project) {
                $envIds = Environment::query()->where('project_id', $project->id)->pluck('id')->all();
                $reachable = array_values(array_intersect($envIds, $accessibleIds));

                if ($reachable === [] && ! ($member?->all_environments ?? false)) {
                    continue;
                }

                $cards[] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status,
                    'environments' => count(($member?->all_environments ?? false) ? $envIds : $reachable),
                    'limit' => $project->environment_limit,
                ];
            }
        }

        return [
            'projects' => $cards,
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

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($projects as $project)
            <a href="{{ route('workspace.projects.show', $project['id']) }}"
               class="rounded-xl border p-5 transition-colors hover:bg-[var(--surface-2)]" style="border-color:var(--border)">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium truncate">{{ $project['name'] }}</span>
                    <span class="badge shrink-0">{{ $project['status'] }}</span>
                </div>
                <p class="mt-3 text-sm" style="color:var(--muted)">
                    <span class="font-semibold tabular-nums" style="color:var(--foreground)">{{ $project['environments'] }} of {{ $project['limit'] }}</span>
                    {{ \Illuminate\Support\Str::plural('environment', $project['limit']) }}
                </p>
                <span class="mt-2 inline-block text-xs" style="color:var(--accent)">Open →</span>
            </a>
        @empty
            <div class="sm:col-span-2 lg:col-span-3 rounded-xl border p-8 text-center" style="border-color:var(--border)">
                <p class="font-medium">No projects yet</p>
                <p class="mx-auto mt-1 max-w-md text-sm" style="color:var(--muted)">A <strong>project</strong> is one product's IdP (Clerk calls it an Application). It holds isolated <strong>environments</strong> — production and sandbox — each with its own users, keys and sign-in, and is billed on its own plan.</p>
                @if ($canManage)
                    <a href="{{ route('workspace.projects.create') }}" class="btn btn-primary btn-sm mt-4"><x-icon name="plus" class="w-4 h-4" /> Create your first project</a>
                @endif
            </div>
        @endforelse
    </div>
</div>
