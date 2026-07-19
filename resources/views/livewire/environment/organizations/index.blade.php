<?php

declare(strict_types=1);

use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Organizations (list). The environment's tenants; each
 * row deep-links to its own detail page. Soft-deleted orgs (status Deleted) are
 * hidden. Creation is a dedicated page — the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Organizations'])] class extends Component
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
        $query = Organization::query()
            ->where('status', '!=', OrganizationStatus::Deleted->value)
            ->orderBy('name');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%"));
        }

        return ['organizations' => $query->paginate(25)];
    }
}; ?>

<div>
    <x-page-header title="Organizations" subtitle="The tenants inside this environment. Each has its own users, roles, and SSO.">
        <x-slot:actions>
            <a href="{{ route('environment.organizations.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New organization</a>
        </x-slot:actions>
    </x-page-header>

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
                @php $variant = match ($org->status) { OrganizationStatus::Active => 'badge-success', OrganizationStatus::Suspended => 'badge-warn', OrganizationStatus::Deleted => 'badge-danger', default => '' }; @endphp
                <span class="badge {{ $variant }}">{{ $org->status->value }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="search" class="w-5 h-5" /></div>
                    <h3>No matches</h3>
                    <p>No organizations match “{{ trim($search) }}”. Try a different name or handle.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div>
                    <h3>No organizations yet</h3>
                    <p>Organizations are the tenants inside this environment. Create the first one to get started.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4 max-w-full overflow-x-auto">{{ $organizations->links() }}</div>
</div>
