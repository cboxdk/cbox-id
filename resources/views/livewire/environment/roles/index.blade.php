<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Environment control plane › Roles (list). Every role this environment owns — the
 * roles an admin authors here plus the read-only ones an application declares through
 * its manifest (the Role model's BelongsToEnvironment scope keeps the query on this
 * plane). Each row deep-links to the role's own detail page, where its permissions
 * are edited; creation is a dedicated page. No inline forms — the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Roles'])] class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = Role::query()->orderBy('name')->limit(100);

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        $roles = $query->get();

        /** @var Collection<string, int> $counts */
        $counts = DB::table('role_permission')
            ->whereIn('role_id', $roles->pluck('id'))
            ->selectRaw('role_id, count(*) as aggregate')
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id');

        return ['roles' => $roles, 'counts' => $counts, 'manifest' => RoleSource::Manifest];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Roles</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">What people can do inside this environment's apps. Assign roles to people; a role is a bundle of permissions.</p>
        </div>
        <a href="{{ route('environment.roles.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New role</a>
    </div>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($roles as $role)
            @php $count = (int) ($counts[$role->id] ?? 0); @endphp
            <a href="{{ route('environment.roles.show', $role->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $role->name }}</span>
                    @if ($role->description)
                        <p class="text-sm truncate" style="color:var(--muted)">{{ $role->description }}</p>
                    @endif
                </div>
                @if ($role->source === $manifest)
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">App</span>
                @endif
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $count }} {{ \Illuminate\Support\Str::plural('permission', $count) }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No roles yet.</p>
        @endforelse
    </div>
</div>
