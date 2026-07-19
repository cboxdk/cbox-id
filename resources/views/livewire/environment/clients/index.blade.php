<?php

declare(strict_types=1);

use Cbox\Id\OAuthServer\Models\Client;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Applications (list). Every OAuth client (relying party)
 * registered in this environment. Clients are environment-owned (BelongsToEnvironment),
 * so the query only ever surfaces this plane's applications — an id from another
 * environment never appears. Each row deep-links to the client's own detail page where
 * the full lifecycle lives (edit, rotate secret, delete); creation is its own page.
 * No inline forms — the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Applications'])] class extends Component
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
        $query = Client::query()->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('client_id', 'like', "%{$term}%"));
        }

        return ['clients' => $query->paginate(25)];
    }
}; ?>

<div>
    <x-page-header title="Applications" subtitle="Every OAuth client registered in this environment — for signing people in or for machine-to-machine API access.">
        <x-slot:actions>
            <a href="{{ route('environment.clients.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New application</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name or client ID">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($clients as $client)
            <a href="{{ route('environment.clients.show', $client->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $client->name }}</span>
                    <p class="text-sm truncate mono" style="color:var(--muted)">{{ $client->client_id }}</p>
                </div>
                @if ($client->first_party)
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">First-party</span>
                @endif
                <span class="badge">{{ ucfirst($client->type->value) }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="clients" class="w-5 h-5" /></div>
                    <h3>No matching applications</h3>
                    <p>No application matches "{{ $search }}". Try a different name or client ID.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="clients" class="w-5 h-5" /></div>
                    <h3>No applications yet</h3>
                    <p>Register one to sign people in or to call the API.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $clients->links() }}</div>
</div>
