<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\AccountCapabilities;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Platform\Contracts\Accounts;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Identity platform › Account settings — account-level settings. Management-only; deletion is
 * deliberately not a self-serve button (it would tear down live IdPs) and is
 * handled as a support request for now.
 */
new #[Layout('components.layouts.app', ['title' => 'Account settings'])] class extends Component
{
    public string $name = '';

    public function mount(AccountAuth $auth, ConsoleScope $scope): mixed
    {
        $account = $auth->current()?->account;

        if ($account === null || $scope->capabilities()?->canManageMembers() !== true) {
            return redirect()->route('projects');
        }

        $this->name = $account->name;

        return null;
    }

    public function save(AccountAuth $auth, Accounts $accounts): void
    {
        $account = $auth->current()?->account;

        if ($account === null || ! AccountCapabilities::of($auth->current()->role)->canManageMembers()) {
            return;
        }

        $this->validate(['name' => ['required', 'string', 'max:120']]);

        $accounts->rename($account->id, trim($this->name));
        $this->dispatch('toast', message: 'Account settings saved.');
    }
}; ?>

<div>
    <x-page-header title="Account settings" subtitle="Manage the account these identity providers belong to." />

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
        <p class="text-sm font-medium">Delete account</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">Deleting an account tears down every project and environment it owns. To protect live IdPs this isn't self-serve — contact support to proceed.</p>
    </div>
</div>
