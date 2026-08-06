@php($pending = (int) ($pending ?? 0))
<div class="card p-5">
    <div class="flex items-center gap-3">
        <span class="cbx-stat-icon {{ $pending > 0 ? 'cbx-stat-icon--warning' : 'cbx-stat-icon--success' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 1.5 3 4.2v4.4c0 4 2.8 7.7 7 9.9 4.2-2.2 7-5.9 7-9.9V4.2L10 1.5Zm3.03 6.28a.75.75 0 0 0-1.06-1.06L9 9.69 8.03 8.72a.75.75 0 0 0-1.06 1.06l1.5 1.5a.75.75 0 0 0 1.06 0l3.5-3.5Z" clip-rule="evenodd" />
            </svg>
        </span>
        <div>
            <p class="text-sm font-medium" style="color:var(--muted)">Audit export</p>
            {{-- The display face, like every neighbouring stat card. `mono` set this one
                 tile in the code face on a row of dashboard cards, so it read as a
                 machine value rather than as a count of work outstanding. --}}
            <p class="text-lg font-semibold" style="color:var(--foreground);font-variant-numeric:tabular-nums">
                {{ number_format($pending) }} pending
            </p>
        </div>
    </div>
    {{-- The run line is ENVIRONMENT bookkeeping: one run walks every chain in the
         environment, so "17 scopes scanned" describes other tenants' activity and
         answers nothing about your own. It is shown to the plane that owns the
         environment; the organization plane gets a sentence about its own chain
         instead. Same rule the exports page states at length. --}}
    <p class="mt-3 text-xs" style="color:var(--muted)">
        @if ($showsRuns ?? false)
            @if ($lastRun ?? null)
                Last run {{ $lastRun->finished_at?->diffForHumans() ?? '—' }} · {{ $lastRun->status }}
            @elseif ($pending > 0)
                {{-- "25 pending" over a flat "No export runs yet" read as a contradiction:
                     one line counts work, the next says none has been done, and neither
                     says what to do. Name the state and the next step instead. --}}
                Nothing has been exported yet — these entries are waiting for the first run.
            @else
                No export runs yet, and nothing is waiting for one.
            @endif
        @else
            {{ $pending === 0 ? 'This organization’s audit chain has shipped in full.' : 'From this organization’s audit chain.' }}
        @endif
    </p>
    <a href="{{ route('compliance.exports') }}" class="mt-4 inline-block text-sm font-medium" style="color:var(--accent-strong)">
        View exports &amp; retention &rarr;
    </a>
</div>
