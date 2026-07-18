<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
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
    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'policies' => SodPolicy::query()->orderByDesc('id')->get(),
            'roleNames' => Role::query()->orderBy('name')->pluck('name', 'id'),
            'orgNames' => Organization::query()->orderBy('name')->pluck('name', 'id'),
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Conflict rules</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Declare sets of roles that must never be held together, then detect subjects who already hold a toxic combination.</p>
        </div>
        <a href="{{ route('environment.sod-policies.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New rule</a>
    </div>

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($policies as $policy)
            <a href="{{ route('environment.sod-policies.show', $policy->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $policy->name }}</span>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $policy->organization_id ? ($orgNames[$policy->organization_id] ?? $policy->organization_id) : 'Environment-wide' }}</span>
                        @foreach ($policy->role_ids as $roleId)
                            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $roleNames[$roleId] ?? $roleId }}</span>
                        @endforeach
                    </div>
                </div>
                @if ($policy->active)
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Active</span>
                @else
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Inactive</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No conflict rules yet. Define one to forbid a toxic combination of roles.</p>
        @endforelse
    </div>
</div>
