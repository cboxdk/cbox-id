<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Platform\AccountProvisioner;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace › Projects › New — stand up another IdP product under the same account.
 * A new project is separately billed (its own plan/environment allowance), so a
 * customer runs "Product 1" and "Product 2" from one login without a second email.
 * On success we route into the new project (empty of environments — the member adds
 * them there).
 */
new #[Layout('components.layouts.workspace', ['title' => 'New project'])] class extends Component
{
    public string $name = '';

    public function create(AccountAuth $auth, AccountProvisioner $provisioner): mixed
    {
        $member = $auth->current();
        $account = $member?->account;

        // Only roles that manage environments may stand up a new product.
        if ($account === null || ($member?->role->canManageEnvironments() ?? false) === false) {
            abort(403);
        }

        $this->validate(['name' => 'required|string|max:120']);

        $project = $provisioner->addProject($account, trim($this->name));

        session()->flash('status', 'Project created — add its first environment.');

        return $this->redirectRoute('workspace.projects.show', ['project' => $project->id], navigate: true);
    }
}; ?>

<div>
    <a href="{{ route('workspace.home') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Projects</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New project</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">A separate IdP product with its own environments and plan — billed independently of your other projects.</p>

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="name">Project name</label>
            <input wire:model="name" id="name" type="text" class="input" placeholder="Product Two" autofocus>
            <p class="mt-1 text-xs" style="color:var(--faint)">You'll add its environments (production, staging, sandbox) next.</p>
            @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create project</button>
            <a href="{{ route('workspace.home') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
