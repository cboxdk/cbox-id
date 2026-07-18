<?php

declare(strict_types=1);

use Cbox\Id\Governance\Models\CertificationCampaign;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Environment control plane › Access reviews (list). Every access-certification
 * campaign this environment owns; each row deep-links to its own detail page where
 * items are certified or revoked. Opening a review is a dedicated page — the list
 * only lists.
 *
 * Campaigns are environment-owned (BelongsToEnvironment), so the query resolves ONLY
 * within this environment; an id minted in another plane never matches, closing
 * cross-tenant leakage.
 */
new #[Layout('components.layouts.environment', ['title' => 'Access reviews'])] class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = CertificationCampaign::query()->orderByDesc('created_at');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return [
            'campaigns' => $query->get(),
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Access reviews</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Periodically certify who holds which role and membership. Revoked access is applied when the review closes.</p>
        </div>
        <a href="{{ route('environment.governance.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New review</a>
    </div>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($campaigns as $c)
            <a href="{{ route('environment.governance.show', $c->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $c->name }}</span>
                    <p class="text-xs truncate" style="color:var(--faint)">{{ $c->created_at?->diffForHumans() }}</p>
                </div>
                @if ($c->status === \Cbox\Id\Governance\Enums\CampaignStatus::Open)
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Open</span>
                @else
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--success-soft);color:var(--success)">Closed</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No reviews yet. Open a review to certify access.</p>
        @endforelse
    </div>
</div>
