<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Platform\AccountProvisioner;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Identity platform › Projects › New — stand up another IdP product under the same account.
 * A new project is separately billed (its own plan/environment allowance), so a
 * customer runs "Product 1" and "Product 2" from one login without a second email.
 * On success we route into the new project (empty of environments — the member adds
 * them there).
 */
new #[Layout('components.layouts.app', ['title' => 'New project'])] class extends Component
{
    public string $name = '';

    /**
     * Re-asked in boot(), which is the only hook that runs on EVERY Livewire action.
     *
     * A mount() guard is a page-load guard: the snapshot the first render hands the
     * browser can be replayed straight at /livewire/update, and mount() never runs again.
     * The scope is what decides this page exists at all, so it is what has to be re-asked.
     */
    public function boot(ConsoleScope $scope): void
    {
        abort_unless($scope->accountRole() !== null, 403);
    }

    public function create(AccountAuth $auth, AccountProvisioner $provisioner): mixed
    {
        $member = $auth->current();
        $account = $member?->account;

        // Only roles that manage environments may stand up a new product.
        if ($account === null || ! $member->role->canManageEnvironments()) {
            abort(403);
        }

        $this->validate(['name' => 'required|string|max:120']);

        $project = $provisioner->addProject($account, trim($this->name));

        $this->dispatch('toast', message: 'Project created — add its first environment.');

        return $this->redirectRoute('projects.show', ['project' => $project->id], navigate: true);
    }
}; ?>

<div>
    <a href="{{ route('projects') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Projects</a>
    <x-page-header class="mt-2" title="New project" subtitle="A separate IdP product with its own environments and plan — billed independently of your other projects." />

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="name">Project name</label>
            <input wire:model="name" id="name" type="text" class="input" placeholder="Product Two" autofocus>
            <p class="mt-1 text-xs" style="color:var(--faint)">You'll add its environments (production, staging, sandbox) next.</p>
            @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create project</button>
            <a href="{{ route('projects') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
