<?php

declare(strict_types=1);

use Cbox\Id\AuditStreaming\Models\AuditStream;
use Livewire\Attributes\Layout;
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
    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'streams' => AuditStream::query()->orderByDesc('created_at')->get(),
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
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Log streaming</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Mirror this environment's hash-chained audit trail out to your SIEM (Splunk, Elastic, Graylog, CEF). Delivery is at-least-once and environment-isolated.</p>
        </div>
        <a href="{{ route('environment.audit-streams.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New stream</a>
    </div>

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($streams as $stream)
            <a href="{{ route('environment.audit-streams.show', $stream->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $stream->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $stream->endpoint_url }}</p>
                </div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $destinationLabels[$stream->destination->value] ?? $stream->destination->value }}</span>
                @if ($stream->enabled)
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Enabled</span>
                @else
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Disabled</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No streams yet. Add one to export this environment's audit trail to your SIEM.</p>
        @endforelse
    </div>
</div>
