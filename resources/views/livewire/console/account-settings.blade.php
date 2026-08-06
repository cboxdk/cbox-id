<?php

declare(strict_types=1);

use App\Platform\OrganizationCapabilities;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Organization\Contracts\Organizations;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Identity platform › Organization settings — organization-level settings. Management-only; deletion is
 * deliberately not a self-serve button (it would tear down live IdPs) and is
 * handled as a support request for now.
 */
new #[Layout('components.layouts.app', ['title' => 'Account settings'])] class extends Component
{
    public string $name = '';

    public function mount(ConsoleScope $scope, Organizations $organizations): mixed
    {
        $account = ($id = $scope->organizationId()) === null ? null : $organizations->find($id);

        if ($account === null || $scope->capabilities()?->canManageMembers() !== true) {
            return redirect()->route('projects');
        }

        $this->name = $account->name;

        return null;
    }

    public function save(AccountAuth $auth, Accounts $accounts): void
    {
        $account = $auth->current()?->account;

        if ($account === null || ! app(ConsoleScope::class)->capabilities()?->canManageMembers() === true) {
            return;
        }

        $this->validate(['name' => ['required', 'string', 'max:120']]);

        $organizations->updateSettings($account->id, []) && $account->forceFill(['name' => trim($this->name)])->save();
        $this->dispatch('toast', message: 'Account settings saved.');
    }
}; ?>

<div>
    <x-page-header title="Organization settings" subtitle="Manage the organization these identity providers belong to." />

    <form wire:submit="save" class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        <label class="label" for="name">Account name</label>
        <p class="mt-1 text-sm" style="color:var(--muted)">Shown across the console.</p>
        <div class="mt-3 flex items-start gap-2">
            <div class="flex-1 max-w-sm">
                <input wire:model="name" id="name" type="text" class="input" placeholder="Acme Inc.">
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">Save</button>
        </div>
    </form>

    <div class="mt-4 rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Delete organization</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">Deleting an organization tears down every project and environment it owns. To protect live IdPs this isn't self-serve — contact support to proceed.</p>
    </div>
</div>
