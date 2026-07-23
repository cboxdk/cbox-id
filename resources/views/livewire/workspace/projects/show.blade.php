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
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › Project — one IdP product's detail: its environments (each opening to
 * its own admin console via a signed handoff) and its plan. Creating environments
 * respects THIS project's plan allowance; the project is the billing anchor, so a
 * member can run several products under one login, billed separately.
 *
 * Access is re-checked here: the project must belong to the member's account, and
 * the member must be able to reach it (all-access, or at least one env in it).
 */
new #[Layout('components.layouts.workspace', ['title' => 'Project'])] class extends Component
{
    public string $projectId = '';

    public string $newEnvironment = '';

    public string $newEnvironmentType = 'production';

    public string $editName = '';

    public function mount(string $project, AccountAuth $auth, AccountMembers $members): void
    {
        $member = $auth->current();
        $model = Project::query()->whereKey($project)->first();

        // Deny-by-default: unknown project, or one this member's account doesn't own.
        abort_if($model === null || $member === null || $model->account_id !== $member->account_id, 404);

        // A scoped member must have at least one reachable environment in it.
        if (! $member->all_environments) {
            $accessible = $members->accessibleEnvironmentIds($member);
            $inProject = Environment::query()->where('project_id', $model->id)->whereIn('id', $accessible)->exists();
            abort_unless($inProject, 403);
        }

        $this->projectId = $model->id;
        $this->editName = $model->name;
    }

    private function project(AccountAuth $auth): Project
    {
        $member = $auth->current();
        $model = Project::query()->whereKey($this->projectId)->first();
        abort_if($model === null || $member === null || $model->account_id !== $member->account_id, 404);

        return $model;
    }

    /**
     * A project management action (rename, add environment, suspend) requires the
     * environment-manager capability AND full, non-scoped access — a member confined
     * to specific environments must not manage the whole project. Aborts 403 on a
     * direct call the UI would never surface for them.
     */
    private function assertCanManage(AccountAuth $auth): void
    {
        $member = $auth->current();
        abort_unless($member !== null && $member->role->canManageEnvironments() && $member->all_environments, 403);
    }

    public function rename(AccountAuth $auth, Projects $projects): void
    {
        $this->assertCanManage($auth);

        $this->validate(['editName' => 'required|string|max:120']);
        $projects->rename($this->project($auth)->id, trim($this->editName));
        $this->dispatch('toast', message: 'Project renamed.');
    }

    public function addEnvironment(AccountAuth $auth, AccountProvisioner $provisioner, AccountActivity $activity): void
    {
        $this->assertCanManage($auth);

        $this->validate([
            'newEnvironment' => 'required|string|max:120',
            'newEnvironmentType' => ['required', Rule::enum(EnvironmentType::class)],
        ]);

        try {
            $environment = $provisioner->addEnvironment($this->project($auth), trim($this->newEnvironment), type: EnvironmentType::from($this->newEnvironmentType));
        } catch (EnvironmentLimitReached) {
            $this->addError('newEnvironment', 'This project is at its environment limit. Upgrade its plan to add more.');

            return;
        }

        $member = $auth->current();
        if ($member !== null) {
            $activity->record($member->account_id, 'account.environment_created', $member->id,
                targetType: 'environment', targetId: $environment->id,
                context: ['name' => trim($this->newEnvironment), 'type' => $this->newEnvironmentType], request: request());
        }

        $this->newEnvironment = '';
        $this->newEnvironmentType = 'production';
        $this->dispatch('toast', message: 'Environment created.');
    }

    public function suspend(AccountAuth $auth, Projects $projects): void
    {
        $this->assertCanManage($auth);
        $projects->suspend($this->project($auth)->id);
        $this->dispatch('toast', message: 'Project suspended — its environments stay live but no new ones can be added until reactivated.');
    }

    public function reactivate(AccountAuth $auth, Projects $projects): void
    {
        $this->assertCanManage($auth);
        $projects->reactivate($this->project($auth)->id);
        $this->dispatch('toast', message: 'Project reactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth, Projects $projects, AccountMembers $members): array
    {
        $member = $auth->current();
        $project = $this->project($auth);
        $accessibleIds = $member === null ? [] : $members->accessibleEnvironmentIds($member);
        $scoped = $member !== null && ! $member->all_environments;

        // Re-validate a scoped member's reachability on every render (mount only runs
        // once), so a grant revoked mid-session stops leaking the project's metadata.
        if ($scoped) {
            abort_unless(
                Environment::query()->where('project_id', $project->id)->whereIn('id', $accessibleIds)->exists(),
                403,
            );
        }

        $query = Environment::query()->where('project_id', $project->id)->orderBy('created_at');
        if ($scoped) {
            $query->whereIn('id', $accessibleIds);
        }

        $base = config('cbox-id.environments.base_domains', []);
        $baseDomain = is_array($base) && $base !== [] ? (string) $base[0] : request()->getHost();

        return [
            'project' => $project,
            'environments' => $query->get(),
            // A management surface requires the capability AND full access.
            'canManage' => $member !== null && $member->role->canManageEnvironments() && $member->all_environments,
            'scoped' => $scoped,
            'remaining' => $projects->remainingEnvironments($project),
            'baseDomain' => $baseDomain,
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('workspace.home') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Projects</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $project->name }}</h1>
            <span class="badge">{{ $project->status }}</span>
        </div>
        <p class="mt-1 text-xs mono select-all" style="color:var(--faint)">{{ $project->slug }} · {{ $project->id }}</p>
    </div>

    {{-- Environments --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center justify-between gap-4">
            <p class="text-sm font-medium">Environments</p>
            @unless ($scoped)
                <span class="text-xs shrink-0" style="color:var(--faint)">{{ $environments->count() }} of {{ $project->environment_limit }} used</span>
            @endunless
        </div>
        <p class="mt-1 text-sm" style="color:var(--muted)">Each is an isolated stage — production or sandbox — with its own users, keys and sign-in. Name one "Staging" for a pre-production stage.</p>

        <div class="mt-4 space-y-2">
            @forelse ($environments as $environment)
                @php
                    $url = $environment->domain !== null
                        ? 'https://'.$environment->domain
                        : 'https://'.$environment->slug.'.'.$baseDomain;
                @endphp
                <div class="rounded-lg border p-4 flex items-center justify-between gap-4" style="border-color:var(--border)" wire:key="env-{{ $environment->id }}">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-medium truncate">{{ $environment->name }}</span>
                            @if ($environment->isSandbox())
                                <span class="badge badge-warn">Sandbox</span>
                            @endif
                            <span class="badge">{{ $environment->status }}</span>
                        </div>
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="mt-1 block text-sm truncate underline underline-offset-2" style="color:var(--accent)">{{ $url }}</a>
                    </div>
                    <a href="{{ route('workspace.environment.open', $environment->id) }}" class="btn btn-primary btn-sm shrink-0">Open ↗</a>
                </div>
            @empty
                <div class="cbx-empty"><div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div><h3>No environments yet</h3><p>Create an environment below to start issuing keys and sign-ins.</p></div>
            @endforelse
        </div>

        @if ($canManage && ! $scoped)
            <form wire:submit="addEnvironment" class="mt-4 flex items-start gap-2">
                <div class="flex-1">
                    <input wire:model="newEnvironment" type="text" class="input" placeholder="Staging" @disabled($remaining <= 0)>
                    @error('newEnvironment') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <select wire:model="newEnvironmentType" class="input" style="width:auto" aria-label="Environment type" @disabled($remaining <= 0)>
                    <option value="production">Production</option>
                    <option value="sandbox">Sandbox</option>
                </select>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="addEnvironment" @disabled($remaining <= 0)>Add environment</button>
            </form>
            @if ($remaining <= 0)
                <p class="mt-2 text-xs" style="color:var(--faint)">This project has used every environment its plan allows. Upgrade its plan to add more.</p>
            @else
                <p class="mt-2 text-xs" style="color:var(--faint)">Sandbox environments allow localhost URLs and never send real email. {{ $remaining }} remaining on this project's plan.</p>
            @endif
        @endif
    </div>

    {{-- Plan (billing anchor lives on the project — one account, separately-billed products) --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Plan</p>
        <div class="mt-3 flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-medium">Early access <span class="text-xs" style="color:var(--faint)">— free</span></p>
                <p class="mt-1 text-xs" style="color:var(--muted)">Up to {{ $project->environment_limit }} environments. Billing per project arrives with general availability.</p>
            </div>
            <button type="button" class="btn btn-ghost btn-sm shrink-0" disabled style="opacity:.6">Upgrade (soon)</button>
        </div>
    </div>

    {{-- Settings --}}
    @if ($canManage)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <p class="text-sm font-medium">Settings</p>
            <form wire:submit="rename" class="mt-4 grid sm:grid-cols-[1fr_auto] gap-2 items-start">
                <div>
                    <label class="label" for="editName">Project name</label>
                    <input wire:model="editName" id="editName" type="text" class="input">
                    @error('editName') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary shrink-0 self-end" wire:loading.attr="disabled" wire:target="rename">Save</button>
            </form>

            <div class="mt-5 pt-5 flex items-center justify-between gap-4" style="border-top:1px solid var(--border)">
                <div>
                    <p class="text-sm font-medium">{{ $project->status === \Cbox\Id\Platform\Enums\ProjectStatus::Suspended ? 'Reactivate project' : 'Suspend project' }}</p>
                    <p class="mt-1 text-xs" style="color:var(--faint)">
                        {{ $project->status === \Cbox\Id\Platform\Enums\ProjectStatus::Suspended
                            ? 'Bring the project back — new environments can be added again.'
                            : 'Existing environments stay live, but no new ones can be added until reactivated.' }}
                    </p>
                </div>
                @if ($project->status === \Cbox\Id\Platform\Enums\ProjectStatus::Suspended)
                    <button type="button" class="btn btn-ghost btn-sm shrink-0" wire:click="reactivate">Reactivate</button>
                @else
                    <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)" wire:click="suspend" wire:confirm="Suspend this project?">Suspend</button>
                @endif
            </div>
        </div>
    @endif
</div>
