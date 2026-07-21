<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
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
    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->current() === null, 403);
    }

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
    <x-page-header title="Access reviews" subtitle="Periodically certify who holds which role and membership. Revoked access is applied when the review closes.">
        <x-slot:actions>
            <a href="{{ route('environment.governance.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New review</a>
        </x-slot:actions>
    </x-page-header>

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
                    <span class="badge badge-warn">Open</span>
                @else
                    <span class="badge badge-success">Closed</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="search" class="w-5 h-5" /></div>
                    <h3>No reviews match "{{ trim($search) }}"</h3>
                    <p>No access review matches that name. Try a different search.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="shield-check" class="w-5 h-5" /></div>
                    <h3>No access reviews yet</h3>
                    <p>Open a review to certify who holds which role and membership across an organization.</p>
                </div>
            @endif
        @endforelse
    </div>
</div>
