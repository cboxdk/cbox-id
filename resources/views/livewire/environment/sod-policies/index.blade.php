<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Environment control plane › Conflict rules (list). Segregation-of-Duties policies
 * that declare sets of roles which must never be held together. Each row deep-links
 * to the rule's own detail page, where the activate/deactivate toggle, the per-org
 * evaluate tool, and deletion live. Creation is a dedicated page — the list only
 * lists.
 *
 * Policies are environment-owned (BelongsToEnvironment), so the model's global scope
 * only ever resolves records inside this environment — an id from another plane never
 * matches, closing cross-tenant id tampering. Access is gated by the env-admin
 * session (route middleware).
 */
new #[Layout('components.layouts.environment', ['title' => 'Conflict rules'])] class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = SodPolicy::query()->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return [
            'policies' => $query->get(),
            'roleNames' => Role::query()->orderBy('name')->pluck('name', 'id'),
            'orgNames' => Organization::query()->orderBy('name')->pluck('name', 'id'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Conflict rules" subtitle="Declare sets of roles that must never be held together, then detect subjects who already hold a toxic combination.">
        <x-slot:actions>
            <a href="{{ route('environment.sod-policies.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New rule</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($policies as $policy)
            <a href="{{ route('environment.sod-policies.show', $policy->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $policy->name }}</span>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        <span class="badge">{{ $policy->organization_id ? ($orgNames[$policy->organization_id] ?? $policy->organization_id) : 'Environment-wide' }}</span>
                        @foreach ($policy->role_ids as $roleId)
                            <span class="badge">{{ $roleNames[$roleId] ?? $roleId }}</span>
                        @endforeach
                    </div>
                </div>
                @if ($policy->active)
                    <span class="badge badge-success">Active</span>
                @else
                    <span class="badge">Inactive</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="search" class="w-5 h-5" /></div>
                    <h3>No rules match "{{ trim($search) }}"</h3>
                    <p>No conflict rule matches that name. Try a different search.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="shield" class="w-5 h-5" /></div>
                    <h3>No conflict rules yet</h3>
                    <p>Define one to forbid a toxic combination of roles being held together.</p>
                </div>
            @endif
        @endforelse
    </div>
</div>
