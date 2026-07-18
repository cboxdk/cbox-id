<?php

declare(strict_types=1);

use Cbox\Id\Identity\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Environment control plane › Users (list). Searchable roster of every end-user
 * identity in the environment (host-resolved env scope). Each row deep-links to the
 * user's own routable detail page where the full lifecycle lives; creation is its own
 * page. No inline forms — the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Users'])] class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = User::query()->orderBy('email')->limit(100);

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(fn ($q) => $q->where('email', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"));
        }

        return ['users' => $query->get()];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Users</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Every end-user identity in this environment.</p>
        </div>
        <a href="{{ route('environment.users.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New user</a>
    </div>

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
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Unverified</span>
                @endunless
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $user->status->value }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No users match.</p>
        @endforelse
    </div>
</div>
