<?php

declare(strict_types=1);

use Cbox\Id\Identity\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Users (list). Searchable roster of every end-user
 * identity in the environment (host-resolved env scope). Each row deep-links to the
 * user's own routable detail page where the full lifecycle lives; creation is its own
 * page. No inline forms — the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Users'])] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = User::query()->orderBy('email');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(fn ($q) => $q->where('email', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"));
        }

        return ['users' => $query->paginate(25)];
    }
}; ?>

<div>
    <x-page-header title="Users" subtitle="Every end-user identity in this environment.">
        <x-slot:actions>
            <a href="{{ route('environment.users.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New user</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by email or name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($users as $user)
            <a href="{{ route('environment.users.show', $user->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $user->name ?? $user->email }}</span>
                    <p class="text-sm truncate mono" style="color:var(--muted)">{{ $user->email }}</p>
                </div>
                @unless ($user->email_verified_at)
                    <span class="badge badge-warn">Unverified</span>
                @endunless
                @php $variant = match ($user->status->value) { 'active' => 'badge-success', 'disabled' => 'badge-warn', 'locked' => 'badge-danger', default => '' }; @endphp
                <span class="badge {{ $variant }}">{{ $user->status->value }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="search" class="w-5 h-5" /></div>
                    <h3>No matches</h3>
                    <p>No users match “{{ trim($search) }}”. Try a different email or name.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="members" class="w-5 h-5" /></div>
                    <h3>No users yet</h3>
                    <p>Every end-user identity in this environment appears here. Create the first user to get started.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4 max-w-full overflow-x-auto">{{ $users->links() }}</div>
</div>
