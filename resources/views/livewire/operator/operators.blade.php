<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Manage platform operators — the identities above every environment. Operators
 * are never environment-owned, so this list is global (no scope to suspend).
 */
new #[Layout('components.layouts.operator', ['title' => 'Operators'])] class extends Component
{
    public bool $creating = false;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    /** Re-check operator auth on every request, including Livewire actions. */
    public function boot(OperatorAuth $auth): void
    {
        abort_unless($auth->check(), 403);
    }

    public function create(PlatformOperators $operators): void
    {
        $this->validate([
            'name' => 'required|string|max:190',
            'email' => 'required|email|max:190',
            'password' => 'required|string|min:12',
        ]);

        if ($operators->findByEmail($this->email) !== null) {
            $this->addError('email', 'An operator with that email already exists.');

            return;
        }

        $operators->create($this->email, $this->password, $this->name);

        $this->reset('name', 'email', 'password', 'creating');
        session()->flash('status', 'Operator created.');
    }

    public function toggleStatus(string $id, OperatorAuth $auth): void
    {
        // An operator can never lock themselves out mid-session.
        abort_if($id === $auth->id(), 403);

        $operator = PlatformOperator::query()->find($id);
        if ($operator === null) {
            return;
        }

        // TODO(review): this direct ->update() bypasses the PlatformOperators
        // contract, so suspending/reactivating an operator fires no audit hook. The
        // contract has no suspend()/setStatus() method yet — add one to
        // PlatformOperators so this privileged state change is audited.
        $operator->update(['status' => $operator->isActive() ? 'suspended' : 'active']);
        session()->flash('status', $operator->isActive() ? 'Operator reactivated.' : 'Operator suspended.');
    }

    public function with(OperatorAuth $auth): array
    {
        return [
            'currentId' => $auth->id(),
            'operators' => PlatformOperator::query()->orderBy('created_at')->get()
                ->map(fn (PlatformOperator $o): array => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'email' => $o->email,
                    'active' => $o->isActive(),
                    'lastLogin' => $o->last_login_at,
                ]),
        ];
    }
}; ?>

<div>
    <x-page-header title="Operators" subtitle="Platform operators administer environments across the whole install.">
        <x-slot:actions>
            <button wire:click="$toggle('creating')" class="btn btn-primary">
                <x-icon name="plus" class="w-4 h-4" /> New operator
            </button>
        </x-slot:actions>
    </x-page-header>

    @if ($creating)
        <form wire:submit="create" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[12rem]">
                <label class="label" for="op-name">Name</label>
                <input wire:model="name" id="op-name" type="text" class="input" placeholder="Grace Hopper" autofocus>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1 min-w-[12rem]">
                <label class="label" for="op-email">Email</label>
                <input wire:model="email" id="op-email" type="email" class="input" placeholder="grace@yourco.example">
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1 min-w-[12rem]">
                <label class="label" for="op-password">Password</label>
                <input wire:model="password" id="op-password" type="password" autocomplete="new-password" class="input" placeholder="At least 12 characters">
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create</button>
            <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
        </form>
    @endif

    <div class="card overflow-hidden">
        @foreach ($operators as $op)
            <div class="px-5 py-4 border-b flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                 style="border-color:var(--border)">
                <div class="min-w-0">
                    <p class="text-sm font-semibold truncate">
                        {{ $op['name'] ?? $op['email'] }}
                        @if ($op['id'] === $currentId)
                            <span class="badge align-middle ml-1">You</span>
                        @endif
                        @unless ($op['active'])
                            <span class="badge badge-danger align-middle ml-1">Suspended</span>
                        @endunless
                    </p>
                    <p class="text-xs truncate" style="color:var(--faint)">
                        {{ $op['email'] }} · {{ $op['lastLogin'] ? 'last in '.$op['lastLogin']->diffForHumans() : 'never signed in' }}
                    </p>
                </div>
                @if ($op['id'] !== $currentId)
                    <button wire:click="toggleStatus('{{ $op['id'] }}')" class="btn btn-ghost btn-sm shrink-0">
                        {{ $op['active'] ? 'Suspend' : 'Reactivate' }}
                    </button>
                @endif
            </div>
        @endforeach
    </div>
</div>
