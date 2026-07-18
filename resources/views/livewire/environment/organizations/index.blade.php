<?php

declare(strict_types=1);

use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Environment control plane › Organizations (list). The environment's tenants; each
 * row deep-links to its own detail page. Soft-deleted orgs (status Deleted) are
 * hidden. Creation is a dedicated page — the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Organizations'])] class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = Organization::query()
            ->where('status', '!=', OrganizationStatus::Deleted->value)
            ->orderBy('name')
            ->limit(100);

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%"));
        }

        return ['organizations' => $query->get()];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Organizations</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">The tenants inside this environment. Each has its own users, roles, and SSO.</p>
        </div>
        <a href="{{ route('environment.organizations.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New organization</a>
    </div>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name or handle">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($organizations as $org)
            <a href="{{ route('environment.organizations.show', $org->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $org->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $org->slug }}</p>
                </div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $org->status->value }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No organizations yet.</p>
        @endforelse
    </div>
</div>
