<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Audit log. The environment's tamper-evident,
 * hash-chained audit trail (env-scoped), most recent first, filterable by action.
 */
new #[Layout('components.layouts.environment', ['title' => 'Audit log'])] class extends Component
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
        $query = AuditEntry::query()->orderByDesc('sequence');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(fn ($q) => $q->where('action', 'like', "%{$term}%")->orWhere('target_type', 'like', "%{$term}%"));
        }

        // simplePaginate, not paginate: `paginate()` runs a COUNT(*) over the filtered
        // set on every render, and this table has no retention — it only grows — so
        // that count is a full index scan of the environment's whole audit partition
        // just to render page numbers nobody uses on an append-only feed. A
        // next/previous cursor answers the same question with one LIMIT n+1 read.
        return ['entries' => $query->simplePaginate(25)];
    }
}; ?>

<div>
    <x-page-header title="Audit log" subtitle="A tamper-evident, hash-chained record of every security-relevant action in this environment." />

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Filter by action or target" aria-label="Filter audit log">
        {{-- SC 4.1.3: the list re-renders on a debounced keystroke with no focus
             change, so the result count is the only thing that can report the filter
             narrowed to nothing. Counted on THIS page rather than across the whole
             filtered set — the total is what the removed COUNT(*) was paying for, and
             "no entries on this page" reports the empty filter just as clearly. --}}
        <p role="status" aria-live="polite" class="sr-only">{{ $entries->count() }} {{ \Illuminate\Support\Str::plural('entry', $entries->count()) }} on this page.</p>
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($entries as $e)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate mono text-sm">{{ $e->action }}</span>
                    <p class="text-xs truncate" style="color:var(--muted)">
                        {{ $e->actor_type->value }}{{ $e->actor_id ? ' · '.\Illuminate\Support\Str::limit($e->actor_id, 12) : '' }}{{ $e->target_type ? ' → '.$e->target_type : '' }}
                    </p>
                </div>
                <div class="text-right shrink-0">
                    <span class="block text-xs tabular-nums" style="color:var(--faint)">{{ $e->created_at?->diffForHumans() }}</span>
                    <span class="block text-xs tabular-nums" style="color:var(--faint)">#{{ $e->sequence }}</span>
                </div>
            </div>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="search" class="w-5 h-5" /></div>
                    <h3>No matches for "{{ trim($search) }}"</h3>
                    <p>No audit entries match that action or target. Try a broader term.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="audit" class="w-5 h-5" /></div>
                    <h3>No audit entries yet</h3>
                    <p>Security-relevant actions in this environment will appear here as they happen.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $entries->links() }}</div>
</div>
