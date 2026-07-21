<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Federation\Models\Connection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Single sign-on (list). Every SSO connection in the
 * environment — across all of its organizations — resolved through the Connection
 * model's BelongsToEnvironment scope, so a connection from another plane never leaks
 * in. Each row deep-links to the connection's own detail page; creation is its own
 * page. No inline forms — the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Single sign-on'])] class extends Component
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
        $query = Connection::query()->orderByDesc('created_at');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return ['connections' => $query->paginate(25)];
    }
}; ?>

<div>
    <x-page-header title="Single sign-on" subtitle="Federated SAML and OIDC connections for this environment's organizations.">
        <x-slot:actions>
            <a href="{{ route('environment.connections.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New connection</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($connections as $c)
            <a href="{{ route('environment.connections.show', $c->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $c->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $c->id }}</p>
                </div>
                <span class="cbx-pill cbx-pill--info">{{ strtoupper($c->type->value) }}</span>
                <span class="badge {{ $c->isActive() ? 'badge-success' : '' }}">{{ $c->isActive() ? 'Active' : ucfirst($c->status->value) }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="connections" class="w-5 h-5" /></div>
                    <h3>No matches for "{{ trim($search) }}"</h3>
                    <p>No connections match that name. Try a different search term.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="connections" class="w-5 h-5" /></div>
                    <h3>No connections yet</h3>
                    <p>Federated SAML and OIDC connections you add for this environment's organizations will appear here.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $connections->links() }}</div>
</div>
