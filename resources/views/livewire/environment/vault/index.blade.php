<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Stored tokens (list). The downstream credential vault:
 * the API keys and tokens this environment's AI agents present to third parties
 * (OpenAI, GitHub, …). Each row deep-links to its own detail page where rotation,
 * grants and revocation live; creation is a dedicated page. No inline forms — the
 * list only lists, and a sealed value is NEVER echoed here.
 *
 * Secrets are environment-owned (BelongsToEnvironment), so the query resolves only
 * within this environment — an id from another plane never appears (deny-by-default).
 */
new #[Layout('components.layouts.environment', ['title' => 'Stored tokens'])] class extends Component
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
        $query = VaultSecret::query()->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return [
            'secrets' => $query->paginate(25),
        ];
    }
}; ?>

<div>
    <x-page-header title="Stored tokens" subtitle="Downstream API keys your AI agents present to providers. Each value is sealed at rest and brokered only to explicitly granted clients — it is never shown again after you store it.">
        <x-slot:actions>
            <a href="{{ route('environment.vault.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New secret</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($secrets as $s)
            <a href="{{ route('environment.vault.show', $s->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $s->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $s->provider }}</p>
                </div>
                @if ($s->isRevoked())
                    <span class="badge badge-danger">Revoked</span>
                @elseif ($s->isExpired())
                    <span class="badge badge-warn">Expired</span>
                @else
                    <span class="badge badge-success">Active</span>
                @endif
                @if ($s->owner_type === 'organization')
                    <span class="badge">Org-scoped</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                    <h3>No matching secrets</h3>
                    <p>No secret matches "{{ $search }}". Try a different name.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                    <h3>No secrets yet</h3>
                    <p>Store a downstream API key to broker it to this environment's agents.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $secrets->links() }}</div>
</div>
