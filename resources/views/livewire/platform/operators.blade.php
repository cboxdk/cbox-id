<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Rules\NotBreached;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Exceptions\CannotSuspendLastOperator;
use Cbox\Id\Platform\Models\PlatformOperator;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Manage platform operators — the identities above every environment. Operators
 * are never environment-owned, so this list is global (no scope to suspend).
 */
new #[Layout('components.layouts.workspace', ['title' => 'Operators', 'width' => '72rem'])] class extends Component
{
    public bool $creating = false;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    /** Re-check operator AUTHORITY on every request, including Livewire actions. */
    public function boot(ConsoleScope $scope): void
    {
        abort_unless($scope->isPlatformOperator(), 404);
    }

    public function create(PlatformOperators $operators): void
    {
        // Operators sit ABOVE every environment, so no tenant's AuthPolicy governs them —
        // hence a fixed floor here rather than PasswordMeetsPolicy. They are also the
        // most privileged accounts on the platform, so the breach screen is not optional.
        $this->validate([
            'name' => 'required|string|max:190',
            'email' => 'required|email|max:190',
            'password' => ['required', 'string', 'min:12', 'max:200', new NotBreached],
        ]);

        if ($operators->findByEmail($this->email) !== null) {
            $this->addError('email', 'An operator with that email already exists.');

            return;
        }

        $operators->create($this->email, $this->password, $this->name);

        $this->reset('name', 'email', 'password', 'creating');
        $this->dispatch('toast', message: 'Operator created.');
    }

    public function toggleStatus(string $id, PlatformOperators $operators, ConsoleScope $scope): void
    {
        $actorId = $scope->operator()?->id;
        if ($actorId === null) {
            abort(403);
        }

        $operator = PlatformOperator::query()->find($id);
        if ($operator === null) {
            return;
        }

        if ($operator->isActive()) {
            // Self-lockout guard: never suspend the account you are signed in as.
            // Checked before the contract call so it can't be reached at all.
            if ($id === $actorId) {
                $this->dispatch('toast', message: 'You cannot suspend the operator you are currently signed in as.', severity: 'error');

                return;
            }

            try {
                // Route through the contract so the change is audited; it refuses
                // to suspend the final active operator (would lock everyone out).
                $operators->suspend($id, $actorId);
            } catch (CannotSuspendLastOperator) {
                $this->dispatch('toast', message: 'You cannot suspend the last active operator — the console would lock everyone out.', severity: 'error');

                return;
            }

            $this->dispatch('toast', message: 'Operator suspended.');
        } else {
            $operators->reactivate($id, $actorId);
            $this->dispatch('toast', message: 'Operator reactivated.');
        }
    }

    /** @return array<string, mixed> */
    public function with(ConsoleScope $scope): array
    {
        return [
            'currentId' => $scope->operator()?->id,
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

    <p role="status" aria-live="polite" class="sr-only">{{ $operators->count() }} {{ \Illuminate\Support\Str::plural('operator', $operators->count()) }} found.</p>

    @if ($creating)
        <form wire:submit="create" class="card p-4 mb-5 mt-8 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[12rem]">
                <label class="label" for="op-name">Name</label>
                <input wire:model="name" id="op-name" type="text" class="input" placeholder="Grace Hopper" autofocus
                       @error('name') aria-invalid="true" aria-describedby="op-name-error" @enderror>
                @error('name') <p id="op-name-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1 min-w-[12rem]">
                <label class="label" for="op-email">Email</label>
                <input wire:model="email" id="op-email" type="email" class="input" placeholder="grace@yourco.example"
                       @error('email') aria-invalid="true" aria-describedby="op-email-error" @enderror>
                @error('email') <p id="op-email-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="flex-1 min-w-[12rem]">
                <label class="label" for="op-password">Password</label>
                <input wire:model="password" id="op-password" type="password" autocomplete="new-password" class="input" placeholder="At least 12 characters"
                       aria-describedby="op-password-hint @error('password') op-password-error @enderror"
                       @error('password') aria-invalid="true" @enderror>
                <p id="op-password-hint" class="mt-1 text-xs" style="color:var(--faint)">At least 12 characters, and checked against known breach corpora.</p>
                @error('password') <p id="op-password-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">
                <span class="spinner" wire:loading wire:target="create" aria-hidden="true"></span> Create
            </button>
            <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
        </form>
    @endif

    <div class="cbx-panel overflow-hidden mt-8">
        @foreach ($operators as $op)
            <div wire:key="operator-{{ $op['id'] }}" class="px-5 py-4 border-b flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                 style="border-color:var(--border)">
                <div class="min-w-0">
                    <p class="text-sm font-semibold truncate">
                        {{ $op['name'] ?? $op['email'] }}
                        @if ($op['id'] === $currentId)
                            <span class="cbx-pill cbx-pill--info align-middle ml-1"><span class="dot"></span>You</span>
                        @endif
                        @unless ($op['active'])
                            <span class="cbx-pill cbx-pill--destructive align-middle ml-1"><span class="dot"></span>Suspended</span>
                        @endunless
                    </p>
                    <p class="text-xs truncate" style="color:var(--faint)">
                        {{ $op['email'] }} · {{ $op['lastLogin'] ? 'last in '.$op['lastLogin']->diffForHumans() : 'never signed in' }}
                    </p>
                </div>
                @if ($op['id'] !== $currentId)
                    {{-- Suspending a colleague sat 8px from nothing, unconfirmed and with no
                         undo. Same pattern as Accounts next door: name the person, say what
                         stops working. Reversible on this screen, so wire:confirm rather than
                         the type-to-confirm dialog (which is for actions with no way back). --}}
                    <button wire:click="toggleStatus('{{ $op['id'] }}')" class="btn btn-ghost btn-sm shrink-0"
                            wire:loading.attr="disabled" wire:target="toggleStatus('{{ $op['id'] }}')"
                            wire:confirm="{{ $op['active']
                                ? 'Suspend '.($op['name'] ?? $op['email']).'? They lose access to every platform page on this install immediately, and their existing sessions stop working on the next request. You can reactivate them here.'
                                : 'Reactivate '.($op['name'] ?? $op['email']).'? They regain full platform-operator access to this install.' }}">
                        <span class="spinner" wire:loading wire:target="toggleStatus('{{ $op['id'] }}')" aria-hidden="true"></span>
                        {{ $op['active'] ? 'Suspend' : 'Reactivate' }}
                    </button>
                @endif
            </div>
        @endforeach
    </div>
</div>
