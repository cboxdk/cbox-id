<?php

declare(strict_types=1);

use Cbox\Id\Identity\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Users. Lists the end-user identities across the whole
 * environment (host-resolved env scope), searchable by email/name. Read-only here;
 * lifecycle actions are the env management API's job (users:write).
 */
new #[Layout('components.layouts.environment', ['title' => 'Users'])] class extends Component
{
    public string $search = '';

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = User::query()->orderBy('email')->limit(100);

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(function ($q) use ($term): void {
                $q->where('email', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%");
            });
        }

        return ['users' => $query->get()];
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Users</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Every end-user identity in this environment.</p>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by email or name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($users as $user)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $user->name ?? $user->email }}</span>
                    <p class="text-sm truncate mono" style="color:var(--muted)">{{ $user->email }}</p>
                </div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $user->status->value }}</span>
            </div>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No users match.</p>
        @endforelse
    </div>
</div>
