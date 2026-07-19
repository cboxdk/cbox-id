<?php

declare(strict_types=1);

use Cbox\Id\AuditStreaming\Models\AuditStream;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Environment control plane › Log streaming (list). The SIEM export registry: every
 * stream that mirrors this environment's hash-chained audit trail out to a downstream
 * SIEM (Splunk, Elastic, Graylog, CEF). Each row deep-links to its own detail page
 * where the full lifecycle lives; creation is a dedicated page — the list only lists.
 *
 * Streams are environment-owned ({@see AuditStream} via BelongsToEnvironment), so the
 * query only ever returns streams within THIS environment — an id minted in another
 * plane never appears, closing cross-tenant leakage (deny-by-default).
 */
new #[Layout('components.layouts.environment', ['title' => 'Log streaming'])] class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = AuditStream::query()->orderByDesc('created_at');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return [
            'streams' => $query->get(),
            'destinationLabels' => [
                'splunk_hec' => 'Splunk HEC',
                'elastic_ecs' => 'Elastic (ECS)',
                'graylog_gelf' => 'Graylog (GELF)',
                'cef_http' => 'CEF over HTTP',
                'generic_json' => 'Generic JSON',
            ],
        ];
    }
}; ?>

<div>
    <x-page-header title="Log streaming" subtitle="Mirror this environment's hash-chained audit trail out to your SIEM (Splunk, Elastic, Graylog, CEF). Delivery is at-least-once and environment-isolated.">
        <x-slot:actions>
            <a href="{{ route('environment.audit-streams.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New stream</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($streams as $stream)
            <a href="{{ route('environment.audit-streams.show', $stream->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $stream->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $stream->endpoint_url }}</p>
                </div>
                <span class="badge">{{ $destinationLabels[$stream->destination->value] ?? $stream->destination->value }}</span>
                @if ($stream->enabled)
                    <span class="badge badge-success">Enabled</span>
                @else
                    <span class="badge">Disabled</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="search" class="w-5 h-5" /></div>
                    <h3>No streams match "{{ trim($search) }}"</h3>
                    <p>No log stream matches that name. Try a different search.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="audit" class="w-5 h-5" /></div>
                    <h3>No log streams yet</h3>
                    <p>Add one to export this environment's audit trail to your SIEM.</p>
                </div>
            @endif
        @endforelse
    </div>
</div>
